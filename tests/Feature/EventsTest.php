<?php

declare(strict_types=1);

use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Whatef\Harken\Events\ConnectionStateChanged;
use Whatef\Harken\Events\MessageHandlerFailed;
use Whatef\Harken\Events\NotificationHandlerFailed;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\RuntimeBuilder;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Runtime\Connection\ConnectionClosed;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationDispatcher;
use Whatef\Harken\Runtime\Sources\NotificationInvoker;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Sources\NotificationRoute;
use Whatef\Harken\Runtime\Sources\NotificationRouter;
use Whatef\Harken\Tests\Fixtures\FailingHandler;
use Whatef\Harken\Tests\Fixtures\FlakyHandler;
use Whatef\Harken\Tests\Support\FakeBroker;
use Whatef\Harken\Tests\Support\FakeLoop;

beforeEach(function () {
    FlakyHandler::$failures = 1;
    FlakyHandler::$handled = [];
    config()->set('harken.routes', __DIR__.'/missing.php');
    config()->set('harken.connections.default.leader.lock', 'none');
    config()->set('harken.outbox.enabled', false);
    config()->set('queue.default', 'sync');
    config()->set('queue.failed', ['driver' => 'file', 'path' => sys_get_temp_dir().'/harken-failed-'.bin2hex(random_bytes(4)).'.json']);
});

/**
 * @return array{Runtime, FakeBroker, FakeLoop}
 */
function events_runtime(): array
{
    $loop = new FakeLoop;
    $broker = new FakeBroker(new Mqtt5Codec);
    $runtime = (new RuntimeBuilder(app(), config(), app(HarkenManager::class)))
        ->build('default', config('harken.connections.default'), $loop, $broker->transport);

    return [$runtime, $broker, $loop];
}

it('announces every connection state change, with the error that caused it', function () {
    Event::fake([ConnectionStateChanged::class]);
    Harken::topic('sensors/{id}/status', fn () => null)->broadcast();
    [$runtime, $broker] = events_runtime();

    $runtime->start();
    $broker->accept();
    $broker->grant();
    $broker->drop();

    $events = Event::dispatched(ConnectionStateChanged::class)->all();
    $transitions = array_map(fn (array $e) => $e[0]->from->value.' > '.$e[0]->to->value, $events);

    expect($transitions)->toBe(['disconnected > connecting', 'connecting > connected', 'connected > reconnecting'])
        ->and($events[0][0]->connection)->toBe('main')
        ->and($events[0][0]->clientId)->toBe($runtime->connection->options->clientId)
        ->and($events[1][0]->error)->toBeNull()
        ->and($events[1][0]->sessionPresent)->toBeFalse()
        ->and($events[2][0]->error)->toBeInstanceOf(ConnectionClosed::class)
        ->and($events[2][0]->sessionPresent)->toBeNull();
});

it('announces state changes of the leader connection too, with whether the broker kept its session', function () {
    Event::fake([ConnectionStateChanged::class]);
    Harken::topic('sensors/{id}/up', fn () => null);
    [$runtime, $broker] = events_runtime();

    $runtime->start();
    $broker->use(1)->accept(new ConnAck(sessionPresent: true));

    Event::assertDispatched(ConnectionStateChanged::class, fn (ConnectionStateChanged $e) => $e->connection === 'leader'
        && $e->clientId === 'laravel-harken-leader'
        && $e->to === ConnectionState::Connected
        && $e->sessionPresent === true);
    expect($runtime->status()['leader']['mqtt']['session_present'])->toBeTrue()
        ->and($runtime->status()['mqtt']['session_present'])->toBeNull();
});

it('announces a failed handler and keeps acknowledging', function () {
    Event::fake([MessageHandlerFailed::class]);
    Harken::topic('sensors/{id}/broken', FailingHandler::class)->qos(1)->broadcast();
    [$runtime, $broker] = events_runtime();
    $runtime->start();
    $broker->accept();
    $broker->grant();

    $broker->send(new Publish('sensors/3/broken', 'x', 1, packetId: 5));

    expect($broker->received())->toEqual([new PubAck(5)]);
    Event::assertDispatched(MessageHandlerFailed::class, fn (MessageHandlerFailed $e) => $e->message->param('id') === '3'
        && $e->route->pattern->pattern === 'sensors/{id}/broken'
        && $e->exception->getMessage() === 'Handler failed for sensors/3/broken.'
        && $e->failedJobId === null);
});

it('stores failed messages as retriable jobs when asked to', function () {
    config()->set('harken.handlers.failed_jobs', true);
    Event::fake([MessageHandlerFailed::class]);
    Harken::topic('flaky/{id}', FlakyHandler::class)->qos(1)->broadcast();
    [$runtime, $broker] = events_runtime();
    $runtime->start();
    $broker->accept();
    $broker->grant();

    $broker->send(new Publish('flaky/9', "raw\x00bytes", 1, packetId: 1));

    $failed = app('queue.failer')->all();

    expect($failed)->toHaveCount(1)
        ->and(FlakyHandler::$handled)->toBe([]);
    Event::assertDispatched(MessageHandlerFailed::class, fn (MessageHandlerFailed $e) => $e->failedJobId === $failed[0]->id);

    // What `queue:retry` pushes and a worker then runs: the stored payload as a job.
    (new SyncJob(app(), $failed[0]->payload, 'sync', 'default'))->fire();

    expect(FlakyHandler::$handled)->toHaveCount(1)
        ->and(FlakyHandler::$handled[0]->payload)->toBe("raw\x00bytes")
        ->and(FlakyHandler::$handled[0]->param('id'))->toBe('9')
        ->and(json_decode($failed[0]->payload, true)['displayName'])->toBe(FlakyHandler::class);
});

it('stores a queued route whose job could not be pushed', function () {
    config()->set('harken.handlers.failed_jobs', true);
    config()->set('queue.default', 'unreachable');
    config()->set('queue.connections.unreachable', ['driver' => 'redis', 'connection' => 'nowhere', 'queue' => 'mqtt']);
    config()->set('database.redis.nowhere', ['host' => '127.0.0.1', 'port' => 1]);
    Harken::topic('flaky/{id}', FlakyHandler::class)->qos(1)->broadcast()->queued();
    [$runtime, $broker] = events_runtime();
    $runtime->start();
    $broker->accept();
    $broker->grant();

    $broker->send(new Publish('flaky/1', 'x', 1, packetId: 2));
    $failed = app('queue.failer')->all();

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->connection)->toBe('unreachable')
        ->and($failed[0]->queue)->toBe('mqtt')
        ->and($broker->received())->toEqual([new PubAck(2)]);
});

it('does not store closure handlers', function () {
    config()->set('harken.handlers.failed_jobs', true);
    Harken::topic('closure/{id}', fn () => throw new RuntimeException('boom'))->broadcast();
    [$runtime, $broker] = events_runtime();
    $runtime->start();
    $broker->accept();
    $broker->grant();

    $broker->send(new Publish('closure/1', 'x'));

    expect(app('queue.failer')->all())->toBe([]);
});

it('announces a failed NOTIFY handler', function () {
    Event::fake([NotificationHandlerFailed::class]);
    $router = new NotificationRouter;
    $router->add(new NotificationRoute('outbox_ready', fn () => throw new RuntimeException('sweep failed')));

    (new NotificationDispatcher($router, app(NotificationInvoker::class), app(NotificationObserver::class)))
        ->dispatch(new Notification('outbox_ready', '1'));

    Event::assertDispatched(NotificationHandlerFailed::class, fn (NotificationHandlerFailed $e) => $e->notification->payload === '1'
        && $e->exception->getMessage() === 'sweep failed');
});

it('keeps running when a failure listener throws', function () {
    Event::listen(MessageHandlerFailed::class, fn () => throw new RuntimeException('listener broke'));
    Harken::topic('sensors/{id}/broken', FailingHandler::class)->qos(1)->broadcast();
    [$runtime, $broker] = events_runtime();
    $runtime->start();
    $broker->accept();
    $broker->grant();

    $broker->send(new Publish('sensors/1/broken', 'x', 1, packetId: 8));

    expect($broker->received())->toEqual([new PubAck(8)])
        ->and(app(DispatchObserver::class))->toBeInstanceOf(DispatchObserver::class);
});
