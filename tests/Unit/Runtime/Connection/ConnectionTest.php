<?php

declare(strict_types=1);

use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\PingReq;
use Whatef\Harken\Protocol\Packet\PingResp;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionClosed;
use Whatef\Harken\Runtime\Connection\ConnectionFailed;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Connection\ConnectionRefused;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Connection\PublishRejected;
use Whatef\Harken\Runtime\Connection\SubscriptionRefused;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Tests\Support\FakeBroker;
use Whatef\Harken\Tests\Support\FakeLoop;
use Whatef\Harken\Transport\Endpoint;

/**
 * @return array{Connection, FakeBroker, FakeLoop}
 */
function connection(ProtocolVersion $protocol = ProtocolVersion::V5_0, array $options = []): array
{
    $codec = $protocol->codec();
    $loop = new FakeLoop;
    $broker = new FakeBroker($codec);
    $connection = new Connection(new ConnectionOptions(...$options + [
        'name' => 'default',
        'endpoint' => new Endpoint('broker.test'),
        'clientId' => 'harken-test',
        'backoff' => new ExponentialBackoff(initial: 1.0, max: 8.0, jitter: 0.0),
        'keepAlive' => 10,
        'protocol' => $protocol,
    ]), $broker->transport, $codec, $loop);

    return [$connection, $broker, $loop];
}

/**
 * @return array{state: string, value: mixed}
 */
function settled(PromiseInterface $promise): array
{
    $result = ['state' => 'pending', 'value' => null];
    $promise->then(
        function (mixed $value) use (&$result): void {
            $result = ['state' => 'fulfilled', 'value' => $value];
        },
        function (Throwable $e) use (&$result): void {
            $result = ['state' => 'rejected', 'value' => $e];
        },
    );

    return $result;
}

dataset('protocols', [
    'MQTT 3.1.1' => [ProtocolVersion::V3_1_1],
    'MQTT 5' => [ProtocolVersion::V5_0],
]);

it('connects, subscribes and resolves after the SUBACK', function (ProtocolVersion $protocol) {
    [$connection, $broker] = connection($protocol, ['username' => 'svc', 'password' => 'secret']);
    $connection->subscribe(['sensors/+/temperature' => 1, '$share/g/sensors/+/status' => 0]);
    $started = $connection->connect();

    expect($connection->state())->toBe(ConnectionState::Connecting);

    $connect = $broker->accept();

    expect($connect->clientId)->toBe('harken-test')
        ->and($connect->keepAlive)->toBe(10)
        ->and($connect->username)->toBe('svc')
        ->and($connect->password)->toBe('secret')
        ->and($connection->state())->toBe(ConnectionState::Connected)
        ->and(settled($started)['state'])->toBe('pending');

    $subscribe = $broker->grant();

    expect($subscribe->filters)->toBe(['sensors/+/temperature' => 1, '$share/g/sensors/+/status' => 0])
        ->and(settled($started)['state'])->toBe('fulfilled');
})->with('protocols');

it('resolves right after CONNACK when there is nothing to subscribe to', function () {
    [$connection, $broker] = connection();
    $started = $connection->connect();
    $broker->accept();

    expect(settled($started)['state'])->toBe('fulfilled')
        ->and($broker->received())->toBe([]);
});

it('sends the MQTT 5 session expiry and clean start flag', function () {
    [$connection, $broker] = connection(options: ['cleanSession' => false, 'sessionExpiry' => 300]);
    $connection->connect();
    $connect = $broker->accept(new ConnAck(sessionPresent: true));

    expect($connect->cleanSession)->toBeFalse()
        ->and($connect->sessionExpiryInterval)->toBe(300)
        ->and($connection->sessionPresent())->toBeTrue();
});

it('acknowledges an inbound QoS 1 message after the handler returns', function (ProtocolVersion $protocol) {
    [$connection, $broker] = connection($protocol);
    $seen = [];
    $connection->onMessage(function (Message $message) use (&$seen, $broker) {
        $seen[] = $message;
        $seen[] = $broker->received(); // nothing acknowledged yet
    });
    $connection->connect();
    $broker->accept();

    $properties = $protocol === ProtocolVersion::V5_0 ? new Properties(contentType: 'application/json') : new Properties;
    $broker->send(new Publish('sensors/1/temperature', "\x00\xFFraw", 1, true, false, 7, $properties));

    expect($seen[0])->toEqual(new Message('sensors/1/temperature', "\x00\xFFraw", 1, true, [], $properties))
        ->and($seen[1])->toBe([])
        ->and($broker->received())->toEqual([new PubAck(7)]);
})->with('protocols');

it('does not acknowledge QoS 0 messages', function () {
    [$connection, $broker] = connection();
    $count = 0;
    $connection->onMessage(function () use (&$count) {
        $count++;
    });
    $connection->connect();
    $broker->accept();
    $broker->send(new Publish('a', 'x'), new Publish('b', 'y'));

    expect($count)->toBe(2)->and($broker->received())->toBe([]);
});

it('still acknowledges when the message listener throws, and reports the error', function () {
    [$connection, $broker] = connection();
    $errors = [];
    $connection->onError(function (Throwable $e) use (&$errors) {
        $errors[] = $e->getMessage();
    });
    $connection->onMessage(fn () => throw new RuntimeException('handler failed'));
    $connection->connect();
    $broker->accept();
    $broker->send(new Publish('a', 'x', 1, packetId: 3));

    expect($errors)->toBe(['handler failed'])
        ->and($broker->received())->toEqual([new PubAck(3)]);
});

it('resolves QoS 0 publishes on write and QoS 1 publishes on PUBACK', function (ProtocolVersion $protocol) {
    [$connection, $broker] = connection($protocol);
    $connection->connect();
    $broker->accept();

    $qos0 = settled($connection->publish('cmd/1', 'zero'));
    $qos1 = $connection->publish('cmd/2', 'one', 1, true);
    [$first, $second] = $broker->received();

    expect($qos0['state'])->toBe('fulfilled')
        ->and($first)->toEqual(new Publish('cmd/1', 'zero'))
        ->and($second->qos)->toBe(1)
        ->and($second->retain)->toBeTrue()
        ->and($second->packetId)->not->toBeNull()
        ->and(settled($qos1)['state'])->toBe('pending')
        ->and($connection->inFlightCount())->toBe(1);

    $broker->send(new PubAck($second->packetId));

    expect(settled($qos1)['state'])->toBe('fulfilled')
        ->and($connection->inFlightCount())->toBe(0);
})->with('protocols');

it('rejects a publish when the MQTT 5 PUBACK carries an error, and accepts "no matching subscribers"', function () {
    [$connection, $broker] = connection();
    $connection->connect();
    $broker->accept();

    $denied = $connection->publish('cmd/secret', 'x', 1);
    $unheard = $connection->publish('cmd/nobody', 'x', 1);
    [$a, $b] = $broker->received();
    $broker->send(new PubAck($a->packetId, ReasonCode::NotAuthorized, 'acl'), new PubAck($b->packetId, ReasonCode::NoMatchingSubscribers));

    $result = settled($denied);

    expect($result['state'])->toBe('rejected')
        ->and($result['value'])->toBeInstanceOf(PublishRejected::class)
        ->and($result['value']->reasonCode)->toBe(ReasonCode::NotAuthorized)
        ->and($result['value']->getMessage())->toBe('The broker rejected the publish to [cmd/secret]: 0x87 not authorized (acl).')
        ->and(settled($unheard)['state'])->toBe('fulfilled');
});

it('passes MQTT 5 properties through on publish', function () {
    [$connection, $broker] = connection();
    $connection->connect();
    $broker->accept();
    $properties = new Properties(contentType: 'text/plain', userProperties: ['trace' => ['a', 'b']]);

    $connection->publish('cmd/1', 'x', 0, false, $properties);

    expect($broker->expect(Publish::class)->properties)->toEqual($properties);
});

it('keeps QoS 1 publishes within the broker Receive Maximum, in order', function () {
    [$connection, $broker] = connection();
    $connection->connect();
    $broker->accept(new ConnAck(receiveMaximum: 2));

    foreach (range(1, 5) as $i) {
        $connection->publish("cmd/{$i}", 'x', 1);
    }
    $connection->publish('cmd/qos0', 'x');

    $sent = $broker->received();

    expect(array_map(fn (Publish $p) => $p->topic, $sent))->toBe(['cmd/1', 'cmd/2'])
        ->and($connection->queuedCount())->toBe(4);

    $broker->send(new PubAck($sent[0]->packetId));

    expect(array_map(fn (Publish $p) => $p->topic, $broker->received()))->toBe(['cmd/3']);

    $broker->send(new PubAck($sent[1]->packetId));
    $rest = $broker->received();

    expect(array_map(fn (Publish $p) => $p->topic, $rest))->toBe(['cmd/4']);
});

it('queues publishes while reconnecting and resends unacknowledged ones with DUP', function (ProtocolVersion $protocol) {
    [$connection, $broker, $loop] = connection($protocol);
    $connection->connect();
    $broker->accept();

    $unacked = $connection->publish('cmd/1', 'first', 1);
    $original = $broker->expect(Publish::class);
    $broker->drop();

    expect($connection->state())->toBe(ConnectionState::Reconnecting);

    $queued = $connection->publish('cmd/2', 'second', 1);
    $loop->advance(1.0);
    $broker->accept(new ConnAck(sessionPresent: true));
    [$resent, $next] = $broker->received();

    expect($resent)->toEqual($original->asDuplicate())
        ->and($next->topic)->toBe('cmd/2')
        ->and($next->duplicate)->toBeFalse();

    $broker->send(new PubAck($resent->packetId), new PubAck($next->packetId));

    expect(settled($unacked)['state'])->toBe('fulfilled')
        ->and(settled($queued)['state'])->toBe('fulfilled');
})->with('protocols');

it('reconnects with backoff and subscribes again after the connection drops', function () {
    [$connection, $broker, $loop] = connection();
    $states = [];
    $connection->onStateChange(function (ConnectionState $from, ConnectionState $to) use (&$states) {
        $states[] = $to->value;
    });
    $connection->subscribe(['a/#' => 1]);
    $connection->connect();
    $broker->accept();
    $broker->grant();
    $broker->drop();

    $loop->advance(0.9);
    expect($broker->connections())->toBe(1);

    $loop->advance(0.1);
    $broker->accept();
    $subscribe = $broker->grant();

    expect($broker->connections())->toBe(2)
        ->and($subscribe->filters)->toBe(['a/#' => 1])
        ->and($connection->reconnects())->toBe(1)
        ->and($states)->toBe(['connecting', 'connected', 'reconnecting', 'connecting', 'connected']);
});

it('backs off exponentially across failed attempts and resets after a success', function () {
    [$connection, $broker, $loop] = connection();
    $broker->transport->failNextConnect(new RuntimeException('refused'));
    $connection->connect();

    expect($connection->state())->toBe(ConnectionState::Reconnecting);

    $loop->advance(1.0); // attempt 2 opens; the broker never answers
    $loop->advance(5.0); // CONNACK timeout -> failure 2, wait 2 s
    $loop->advance(1.9);
    expect($broker->connections())->toBe(1);

    $loop->advance(0.1);
    expect($broker->connections())->toBe(2);

    $broker->accept();
    expect($connection->state())->toBe(ConnectionState::Connected);
});

it('retries a transient refusal and fails fast on a permanent one', function () {
    [$connection, $broker, $loop] = connection();
    $started = $connection->connect();
    $broker->accept(new ConnAck(ReasonCode::ServerBusy));

    expect($connection->state())->toBe(ConnectionState::Reconnecting)
        ->and(settled($started)['state'])->toBe('pending');

    $loop->advance(1.0);
    $broker->accept(new ConnAck(ReasonCode::BadUserNameOrPassword));
    $result = settled($started);

    expect($result['state'])->toBe('rejected')
        ->and($result['value'])->toBeInstanceOf(ConnectionRefused::class)
        ->and($result['value']->getMessage())->toBe('The broker refused the MQTT 5.0 connection: 0x86 bad user name or password.')
        ->and($connection->state())->toBe(ConnectionState::Disconnected);

    $loop->advance(60.0);
    expect($broker->connections())->toBe(2);
});

it('reports a broker without MQTT 5 as an unsupported protocol version', function () {
    [$connection, $broker] = connection();
    $started = $connection->connect();
    $broker->received();
    $broker->sendRaw("\x20\x02\x00\x01"); // MQTT 3.1.1 CONNACK, return code 1
    $result = settled($started);

    expect($result['value'])->toBeInstanceOf(ConnectionRefused::class)
        ->and($result['value']->reasonCode)->toBe(ReasonCode::UnsupportedProtocolVersion)
        ->and($result['value']->protocol)->toBe(ProtocolVersion::V5_0);
});

it('maps MQTT 3.1.1 refusals onto reason codes', function () {
    [$connection, $broker] = connection(ProtocolVersion::V3_1_1);
    $started = $connection->connect();
    $broker->accept(new ConnAck(ReasonCode::NotAuthorized));

    expect(settled($started)['value']->getMessage())->toBe('The broker refused the MQTT 3.1.1 connection: 0x87 not authorized.');
});

it('gives up after the configured number of attempts', function () {
    [$connection, $broker, $loop] = connection(options: ['backoff' => new ExponentialBackoff(initial: 1.0, jitter: 0.0, maxAttempts: 2)]);
    $closed = [];
    $connection->onClose(function (?Throwable $reason) use (&$closed) {
        $closed[] = $reason;
    });
    $broker->transport->failNextConnect(new RuntimeException('down'));
    $started = $connection->connect();
    $loop->advance(1.0);
    $broker->drop();
    $result = settled($started);

    expect($result['value'])->toBeInstanceOf(ConnectionFailed::class)
        ->and($result['value']->getMessage())->toStartWith('Gave up connecting after 2 failed attempts')
        ->and($closed)->toHaveCount(1)
        ->and($connection->state())->toBe(ConnectionState::Disconnected);
});

it('fails the start when the broker refuses a subscription', function (ProtocolVersion $protocol) {
    [$connection, $broker] = connection($protocol);
    $connection->subscribe(['ok/#' => 1, 'secret/#' => 1]);
    $started = $connection->connect();
    $broker->accept();
    $broker->grant([ReasonCode::GrantedQos1, $protocol === ProtocolVersion::V5_0 ? ReasonCode::NotAuthorized : ReasonCode::UnspecifiedError]);
    $result = settled($started);

    expect($result['value'])->toBeInstanceOf(SubscriptionRefused::class)
        ->and($result['value']->refused)->toHaveKey('secret/#')
        ->and($connection->state())->toBe(ConnectionState::Disconnected);
})->with('protocols');

it('refuses shared subscriptions when the broker announces it has none', function () {
    [$connection, $broker] = connection();
    $connection->subscribe(['$share/g/a' => 0]);
    $started = $connection->connect();
    $broker->accept(new ConnAck(sharedSubscriptionAvailable: false));

    expect(settled($started)['value'])->toBeInstanceOf(SubscriptionRefused::class)
        ->and(settled($started)['value']->getMessage())->toContain('does not support shared subscriptions');
});

it('reconnects when no SUBACK arrives in time', function () {
    [$connection, $broker, $loop] = connection();
    $connection->subscribe(['a' => 0]);
    $connection->connect();
    $broker->accept();
    $loop->advance(5.0);

    expect($connection->state())->toBe(ConnectionState::Reconnecting);
});

it('enforces the limits the broker announced', function () {
    [$connection, $broker] = connection();
    $connection->connect();
    $broker->accept(new ConnAck(maximumQos: 0, retainAvailable: false, maximumPacketSize: 64));

    $qos = settled($connection->publish('a', 'x', 1));
    $retain = settled($connection->publish('a', 'x', 0, true));
    $large = settled($connection->publish('a', str_repeat('x', 100)));

    expect($qos['value'])->toBeInstanceOf(PublishRejected::class)
        ->and($qos['value']->reasonCode)->toBe(ReasonCode::QosNotSupported)
        ->and($retain['value']->reasonCode)->toBe(ReasonCode::RetainNotSupported)
        ->and($large['value']->reasonCode)->toBe(ReasonCode::PacketTooLarge)
        ->and($broker->received())->toBe([]);
});

it('rejects invalid topics and publishes when not running', function () {
    [$connection] = connection();

    expect(settled($connection->publish('a/+', 'x'))['value'])->toBeInstanceOf(InvalidArgumentException::class)
        ->and(settled($connection->publish('a', 'x'))['value'])->toBeInstanceOf(ConnectionClosed::class);
});

it('bounds the publishes waiting for the connection', function () {
    [$connection, $broker] = connection(options: ['maxQueued' => 2]);
    $broker->transport->failNextConnect(new RuntimeException('down'));
    $connection->connect();
    $connection->publish('a', '1');
    $connection->publish('a', '2');

    expect(settled($connection->publish('a', '3'))['value']->reasonCode)->toBe(ReasonCode::QuotaExceeded);
});

it('pings at half the keepalive interval and reconnects when the broker goes silent', function () {
    [$connection, $broker, $loop] = connection();
    $connection->connect();
    $broker->accept();

    $loop->advance(5.0);
    expect($broker->received())->toEqual([new PingReq]);

    $broker->send(new PingResp);
    $loop->advance(5.0);
    expect($broker->received())->toEqual([new PingReq])
        ->and($connection->state())->toBe(ConnectionState::Connected);

    $loop->advance(5.0); // no answer to the second ping
    expect($connection->state())->toBe(ConnectionState::Reconnecting);
});

it('uses the server keep alive when the broker sets one', function () {
    [$connection, $broker, $loop] = connection();
    $connection->connect();
    $broker->accept(new ConnAck(serverKeepAlive: 4));

    $loop->advance(2.0);
    expect($broker->received())->toEqual([new PingReq]);
});

it('stops for good when the broker hands the session to another client', function () {
    [$connection, $broker, $loop] = connection();
    $closed = [];
    $connection->onClose(function (?Throwable $reason) use (&$closed) {
        $closed[] = $reason;
    });
    $connection->connect();
    $broker->accept();
    $pending = $connection->publish('a', 'x', 1);
    $broker->send(new Disconnect(ReasonCode::SessionTakenOver));
    $loop->advance(120.0);

    expect($connection->state())->toBe(ConnectionState::Disconnected)
        ->and($broker->connections())->toBe(1)
        ->and($closed[0])->toBeInstanceOf(ConnectionClosed::class)
        ->and($closed[0]->isSessionTakenOver())->toBeTrue()
        ->and(settled($pending)['state'])->toBe('rejected');
});

it('reconnects after other server disconnects', function () {
    [$connection, $broker, $loop] = connection();
    $errors = [];
    $connection->onError(function (Throwable $e) use (&$errors) {
        $errors[] = $e->getMessage();
    });
    $connection->connect();
    $broker->accept();
    $broker->send(new Disconnect(ReasonCode::ServerShuttingDown, reasonString: 'maintenance'));

    expect($errors)->toBe(['The broker closed the connection: 0x8B server shutting down (maintenance).']);

    $loop->advance(1.0);
    expect($broker->connections())->toBe(2);
});

it('closes with a DISCONNECT reason code on malformed input and reconnects', function () {
    [$connection, $broker, $loop] = connection();
    $connection->connect();
    $broker->accept();
    $peer = $broker->transport->peer();
    $broker->sendRaw("\x00\x00"); // reserved packet type

    expect($peer->drain())->toBe((new Mqtt5Codec)->encode(new Disconnect(ReasonCode::MalformedPacket)))
        ->and($connection->state())->toBe(ConnectionState::Reconnecting);

    $loop->advance(1.0);
    expect($broker->connections())->toBe(2);
});

it('closes without acknowledging when the guard fails, and does not reconnect', function () {
    [$connection, $broker, $loop] = connection();
    $allowed = true;
    $handled = 0;
    $connection->guard(function () use (&$allowed) {
        return $allowed;
    });
    $connection->onMessage(function () use (&$handled) {
        $handled++;
    });
    $connection->connect();
    $broker->accept();
    $peer = $broker->transport->peer();

    $allowed = false;
    $broker->send(new Publish('a', 'x', 1, packetId: 9));
    $loop->advance(60.0);

    expect($handled)->toBe(0)
        ->and($peer->drain())->toBe('')
        ->and($connection->state())->toBe(ConnectionState::Disconnected)
        ->and($broker->connections())->toBe(1);
});

it('checks the guard before every connection attempt', function () {
    [$connection, $broker] = connection();
    $connection->guard(fn () => false);
    $result = settled($connection->connect());

    expect($result['value'])->toBeInstanceOf(ConnectionClosed::class)
        ->and($broker->connections())->toBe(0);
});

it('disconnects gracefully and rejects what is still waiting', function (ProtocolVersion $protocol) {
    [$connection, $broker, $loop] = connection($protocol);
    $closed = [];
    $connection->onClose(function (?Throwable $reason) use (&$closed) {
        $closed[] = $reason;
    });
    $connection->connect();
    $broker->accept();
    $pending = $connection->publish('a', 'x', 1);
    $peer = $broker->transport->peer();
    $broker->received();

    $done = settled($connection->disconnect());
    $sent = $protocol->codec()->decoder()->push($peer->drain());

    expect($done['state'])->toBe('fulfilled')
        ->and($sent)->toEqual([new Disconnect])
        ->and($connection->state())->toBe(ConnectionState::Disconnected)
        ->and(settled($pending)['value'])->toBeInstanceOf(ConnectionClosed::class)
        ->and($closed)->toBe([null])
        ->and($loop->pendingTimers())->toBe(0);
})->with('protocols');

it('tells when nothing is waiting or in flight', function () {
    [$connection, $broker] = connection();
    $connection->connect();
    $broker->accept();

    expect(settled($connection->whenIdle())['state'])->toBe('fulfilled');

    $connection->publish('a', 'x', 1);
    $idle = $connection->whenIdle();
    expect(settled($idle)['state'])->toBe('pending');

    $broker->send(new PubAck($broker->expect(Publish::class)->packetId));
    expect(settled($idle)['state'])->toBe('fulfilled');
});

it('stops a reconnect in progress when disconnected', function () {
    [$connection, $broker, $loop] = connection();
    $connection->connect();
    $broker->accept();
    $broker->drop();
    $connection->disconnect();
    $loop->advance(60.0);

    expect($broker->connections())->toBe(1)
        ->and($connection->state())->toBe(ConnectionState::Disconnected);
});

it('traces packets in both directions', function () {
    [$connection, $broker] = connection();
    $trace = [];
    $connection->trace(function (string $direction, $packet) use (&$trace) {
        $trace[] = $direction.' '.(new ReflectionClass($packet))->getShortName();
    });
    $connection->connect();
    $broker->accept();

    expect($trace)->toBe(['out Connect', 'in ConnAck']);
});

it('records connection times for status reports', function () {
    [$connection, $broker] = connection();

    expect($connection->connectedAt())->toBeNull();

    $connection->connect();
    $broker->accept();
    $connectedAt = $connection->connectedAt();
    $broker->drop();

    expect($connectedAt)->toBeFloat()
        ->and($connection->disconnectedAt())->toBeGreaterThanOrEqual($connectedAt);
});

it('refuses a codec that speaks another version', function () {
    $loop = new FakeLoop;
    new Connection(
        new ConnectionOptions('default', new Endpoint('broker.test'), 'id', new ExponentialBackoff, protocol: ProtocolVersion::V5_0),
        (new FakeBroker(new BinsoulCodec))->transport,
        new BinsoulCodec,
        $loop,
    );
})->throws(InvalidArgumentException::class, 'The codec speaks MQTT 3.1.1 but the connection is configured for MQTT 5.0.');

it('sends urgent publishes ahead of others waiting for the Receive Maximum', function () {
    [$connection, $broker] = connection();
    $connection->connect();
    $broker->accept(new ConnAck(receiveMaximum: 1));

    $connection->publish('bulk/1', 'x', 1);
    $connection->publish('bulk/2', 'x', 1);
    $connection->publish('bulk/3', 'x', 1);
    $connection->publish('door/open', 'x', 1, urgent: true);
    [$first] = $broker->received();
    $broker->send(new PubAck($first->packetId));
    [$second] = $broker->received();

    expect($first->topic)->toBe('bulk/1')
        ->and($second->topic)->toBe('door/open')
        ->and($connection->queuedCount())->toBe(2);
});

it('holds inbound messages without acknowledging them while it keeps publishing', function () {
    [$connection, $broker] = connection();
    $handled = 0;
    $connection->onMessage(function () use (&$handled) {
        $handled++;
    });
    $connection->connect();
    $broker->accept();

    $connection->holdInbound();
    $broker->send(new Publish('a', 'x', 1, packetId: 4));
    $published = $connection->publish('b', 'y', 1);
    $sent = $broker->received();
    $broker->send(new PubAck($sent[0]->packetId));

    expect($handled)->toBe(0)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0])->toBeInstanceOf(Publish::class)
        ->and(settled($published)['state'])->toBe('fulfilled');
});
