<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\Queue\HandleMessage;
use Whatef\Harken\Laravel\Testing\HarkenFake;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Tests\Fixtures\RecordingHandler;
use Whatef\Harken\Tests\Fixtures\SlowJobHandler;

beforeEach(function () {
    RecordingHandler::$messages = [];
    SlowJobHandler::$messages = [];
});

describe('publishing', function () {
    it('records publishes for assertions', function () {
        $fake = Harken::fake();

        Harken::publish('cmd/door/1', '{"open":true}', qos: 1);
        Harken::clearRetained('state/door/1');

        Harken::assertPublished('cmd/door/1');
        Harken::assertPublished('cmd/door/1', fn (string $payload, int $qos) => $qos === 1 && str_contains($payload, 'open'));
        Harken::assertPublished('state/door/1', fn (string $payload, int $qos, bool $retain) => $payload === '' && $retain);
        Harken::assertNotPublished('cmd/door/2');
        Harken::assertNotPublished('cmd/door/1', fn (string $payload, int $qos) => $qos === 0);

        expect($fake->published('cmd/door/1'))->toHaveCount(1);
    });

    it('fails assertions with a clear message', function () {
        Harken::fake();

        Harken::assertPublished('never/sent');
    })->throws(ExpectationFailedException::class, 'The expected [never/sent] message was not published.');

    it('asserts that nothing was published', function () {
        Harken::fake();
        Harken::assertNothingPublished();

        Harken::publish('a', 'b');

        expect(fn () => Harken::assertNothingPublished())->toThrow(ExpectationFailedException::class, '[a]');
    });

    it('validates topics like the real publisher', function () {
        Harken::fake();

        Harken::publish('a/+', 'b');
    })->throws(InvalidArgumentException::class);

    it('stays type-compatible with the manager binding', function () {
        $fake = Harken::fake();

        expect(app(HarkenManager::class))->toBe($fake)->toBeInstanceOf(HarkenFake::class)
            ->and(Harken::fake())->toBe($fake);
    });
});

describe('simulating inbound messages', function () {
    it('runs inline handlers with route parameters and raw bytes', function () {
        Harken::fake();

        Harken::simulate('sensors/42/temperature', "\x00\x01{\"t\":21.5}", qos: 1);

        expect(RecordingHandler::$messages)->toHaveCount(1);
        $message = RecordingHandler::$messages[0];
        expect($message->topic)->toBe('sensors/42/temperature')
            ->and($message->param('id'))->toBe('42')
            ->and($message->payload)->toBe("\x00\x01{\"t\":21.5}")
            ->and($message->qos)->toBe(1);
    });

    it('dispatches queued routes as jobs instead of running them', function () {
        Queue::fake();
        Harken::fake();

        Harken::simulate('sensors/9/report', 'payload');

        expect(SlowJobHandler::$messages)->toBe([]);
        Queue::assertPushed(HandleMessage::class, fn (HandleMessage $job) => $job->handler === SlowJobHandler::class
            && $job->message->param('id') === '9'
            && $job->message->payload === 'payload');
    });

    it('lets handler exceptions reach the test', function () {
        Harken::fake();

        Harken::simulate('sensors/1/broken', '');
    })->throws(RuntimeException::class, 'Handler failed for sensors/1/broken.');

    it('ignores topics without a route', function () {
        Harken::fake();

        Harken::simulate('nobody/listens', 'x');

        expect(RecordingHandler::$messages)->toBe([]);
    });

    it('lets handlers be called directly without a broker', function () {
        (new RecordingHandler)(new Message('sensors/1/temperature', '{}', params: ['id' => '1']));

        expect(RecordingHandler::$messages[0]->param('id'))->toBe('1');
    });
});

it('runs queued handlers on the worker with the original bytes', function () {
    $payload = "\xff\xfe\x00binary";
    $job = new HandleMessage(SlowJobHandler::class, new Message('sensors/3/report', $payload, 1, false, ['id' => '3']));

    $restored = unserialize(json_decode(json_encode(['c' => serialize($job)], JSON_THROW_ON_ERROR), true)['c']);
    app()->call([$restored, 'handle']);

    expect(SlowJobHandler::$messages[0]->payload)->toBe($payload)
        ->and($restored->displayName())->toBe(SlowJobHandler::class);
});
