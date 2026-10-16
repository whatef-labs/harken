<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Connection\ConnectionState as State;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Topic;

describe('connection state machine', function () {
    it('allows the documented transitions', function (State $from, State $to, bool $allowed) {
        expect($from->canTransitionTo($to))->toBe($allowed);
    })->with([
        [State::Disconnected, State::Connecting, true],
        [State::Disconnected, State::Connected, false],
        [State::Connecting, State::Connected, true],
        [State::Connecting, State::Reconnecting, true],
        [State::Connected, State::Reconnecting, true],
        [State::Connected, State::Connecting, false],
        [State::Reconnecting, State::Connecting, true],
        [State::Reconnecting, State::Connected, false],
        [State::Connected, State::Stopping, true],
        [State::Reconnecting, State::Stopping, true],
        [State::Stopping, State::Disconnected, true],
        [State::Stopping, State::Connecting, false],
    ]);
});

describe('outbox entries', function () {
    it('round-trips through stream fields with raw bytes', function () {
        $message = new OutboxMessage('cmd/door/1', "\x00\x01raw", 1, true, 1_700_000_000_000);

        expect(OutboxMessage::fromFields($message->toFields()))->toEqual($message);
    });

    it('carries MQTT 5 properties as one JSON field', function () {
        $properties = new Properties(contentType: 'application/json', correlationData: "\x00\xffid", userProperties: ['k' => ['1', '2']]);
        $message = new OutboxMessage('cmd/door/1', '{}', 1, false, 1_700_000_000_000, $properties);
        $fields = $message->toFields();

        expect(OutboxMessage::fromFields($fields))->toEqual($message)
            ->and(array_key_exists('properties', (new OutboxMessage('a', 'b'))->toFields()))->toBeFalse();
    });

    it('stamps the enqueue time when missing', function () {
        $fields = (new OutboxMessage('a', 'b'))->toFields();

        expect((int) $fields['enqueued_at'])->toBeGreaterThan(1_700_000_000_000);
    });

    it('rejects malformed entries', function (array $fields) {
        OutboxMessage::fromFields($fields);
    })->with([
        [['payload' => 'x', 'qos' => '0']],
        [['topic' => '', 'payload' => 'x', 'qos' => '0']],
        [['topic' => 'a', 'qos' => '0']],
        [['topic' => 'a', 'payload' => 'x', 'qos' => '2']],
        [['topic' => 'a', 'payload' => 'x', 'qos' => '0', 'properties' => '{not json']],
        [['topic' => 'a', 'payload' => 'x', 'qos' => '0', 'properties' => '{"message_expiry_interval":-1}']],
    ])->throws(UnexpectedValueException::class);
});

describe('publish validation', function () {
    it('accepts concrete topics with QoS 0 or 1', function () {
        Topic::assertPublishable('a/b', 0);
        Topic::assertPublishable('a/b', 1);
    })->throwsNoExceptions();

    it('rejects wildcards, empty topics and unsupported QoS', function (string $topic, int $qos) {
        Topic::assertPublishable($topic, $qos);
    })->with([
        ['', 0],
        ['a/+', 0],
        ['a/#', 1],
        ['a/b', 2],
    ])->throws(InvalidArgumentException::class);
});
