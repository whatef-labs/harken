<?php

declare(strict_types=1);

/*
 * The runtime connection against a real broker (see docker/compose.yml).
 *
 *   HARKEN_TEST_BROKER_HOST=127.0.0.1 vendor/bin/pest --group=integration
 *
 * Optional: HARKEN_TEST_BROKER_PORT (1883), HARKEN_TEST_BROKER_TLS_PORT (8883).
 * The TLS test needs the CA from docker/certs/generate.sh.
 */

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionClosed;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Tests\Support\WireClient;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;
use Whatef\Harken\Transport\TlsOptions;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\all;
use function React\Promise\Timer\timeout;

beforeEach(function () {
    if (! getenv('HARKEN_TEST_BROKER_HOST')) {
        $this->markTestSkipped('Set HARKEN_TEST_BROKER_HOST to run broker integration tests.');
    }
});

// A connection left running keeps timers on the global loop, which ReactPHP
// would otherwise run forever at shutdown after a failed test.
afterEach(function () {
    foreach (it_connections() as $connection) {
        $connection->abort();
    }

    it_connections(reset: true);
});

afterAll(fn () => Loop::stop());

/**
 * @return list<Connection>
 */
function it_connections(?Connection $add = null, bool $reset = false): array
{
    static $connections = [];

    if ($reset) {
        $connections = [];
    }

    if ($add !== null) {
        $connections[] = $add;
    }

    return $connections;
}

function it_connection(ProtocolVersion $protocol, array $options = []): Connection
{
    $host = (string) getenv('HARKEN_TEST_BROKER_HOST');

    $connection = new Connection(new ConnectionOptions(...$options + [
        'name' => 'default',
        'endpoint' => new Endpoint($host, (int) (getenv('HARKEN_TEST_BROKER_PORT') ?: 1883)),
        'clientId' => 'harken-it-'.bin2hex(random_bytes(5)),
        'backoff' => new ExponentialBackoff(initial: 0.2, max: 1.0),
        'keepAlive' => 30,
        'protocol' => $protocol,
    ]), new ReactSocketTransport(Loop::get()), $protocol->codec(), Loop::get());
    it_connections($connection);

    return $connection;
}

function it_topic(string $name): string
{
    static $run = null;
    $run ??= bin2hex(random_bytes(4));

    return "harken-it/{$run}/conn/{$name}";
}

/**
 * @template T
 *
 * @param  PromiseInterface<T>  $promise
 * @return T
 */
function it_await(PromiseInterface $promise, float $seconds = 5.0): mixed
{
    return await(timeout($promise, $seconds));
}

dataset('protocols', [
    'MQTT 3.1.1' => [ProtocolVersion::V3_1_1],
    'MQTT 5' => [ProtocolVersion::V5_0],
]);

it('connects, subscribes and receives its own QoS 1 publish', function (ProtocolVersion $protocol) {
    $connection = it_connection($protocol);
    $topic = it_topic("own-{$protocol->value}");
    $received = new Deferred;
    $connection->onMessage(fn (Message $message) => $received->resolve($message));
    $connection->subscribe([$topic => 1]);

    it_await($connection->connect());
    $properties = $protocol === ProtocolVersion::V5_0
        ? new Properties(contentType: 'application/octet-stream', userProperties: ['k' => ['1', '2']])
        : new Properties;
    it_await($connection->publish($topic, "\x00\x01raw", 1, false, $properties));
    $message = it_await($received->promise());

    expect($message->payload)->toBe("\x00\x01raw")
        ->and($message->qos)->toBe(1)
        ->and($message->properties->contentType)->toBe($properties->contentType)
        ->and($message->properties->userProperties)->toBe($properties->userProperties);

    it_await($connection->disconnect());
})->with('protocols');

it('follows the Receive Maximum EMQX announces while publishing a burst', function () {
    $connection = it_connection(ProtocolVersion::V5_0);
    it_await($connection->connect());
    $window = $connection->limits()->receiveMaximum;
    $peak = 0;
    $promises = [];

    for ($i = 0; $i < 500; $i++) {
        $promises[] = $connection->publish(it_topic('burst'), (string) $i, 1);
        $peak = max($peak, $connection->inFlightCount());
    }

    it_await(all($promises), 10.0);

    expect($window)->toBeLessThan(65535)
        ->and($peak)->toBe($window)
        ->and($connection->inFlightCount())->toBe(0);

    it_await($connection->disconnect());
});

it('reconnects and subscribes again after losing the connection', function () {
    $connection = it_connection(ProtocolVersion::V3_1_1);
    $topic = it_topic('resubscribe');
    $messages = [];
    $connection->onMessage(function (Message $message) use (&$messages) {
        $messages[] = $message->payload;
    });
    $connection->subscribe([$topic => 1]);
    it_await($connection->connect());

    // An MQTT 3.1.1 client with the same id makes the broker drop ours.
    [$intruder] = WireClient::connect(
        (string) getenv('HARKEN_TEST_BROKER_HOST'),
        (int) (getenv('HARKEN_TEST_BROKER_PORT') ?: 1883),
        ProtocolVersion::V3_1_1->codec(),
        new Connect($connection->options->clientId),
    );
    $intruder->drop();
    delay(0.1);

    expect($connection->state())->not->toBe(ConnectionState::Connected);

    $deadline = microtime(true) + 5;
    while ($connection->state() !== ConnectionState::Connected && microtime(true) < $deadline) {
        delay(0.05);
    }
    delay(0.2); // let the SUBACK arrive

    $publisher = it_connection(ProtocolVersion::V3_1_1);
    it_await($publisher->connect());
    it_await($publisher->publish($topic, 'after-reconnect', 1));
    delay(0.3);

    expect($connection->reconnects())->toBe(1)
        ->and($messages)->toBe(['after-reconnect']);

    it_await(all([$connection->disconnect(), $publisher->disconnect()]));
});

it('stops for good when another MQTT 5 client takes the session over', function () {
    $connection = it_connection(ProtocolVersion::V5_0);
    $closed = new Deferred;
    $connection->onClose(fn (?Throwable $reason) => $closed->resolve($reason));
    it_await($connection->connect());

    $takeover = it_connection(ProtocolVersion::V5_0, ['clientId' => $connection->options->clientId]);
    it_await($takeover->connect());
    $reason = it_await($closed->promise());
    delay(1.0);

    expect($reason)->toBeInstanceOf(ConnectionClosed::class)
        ->and($reason->isSessionTakenOver())->toBeTrue()
        ->and($connection->state())->toBe(ConnectionState::Disconnected)
        ->and($takeover->state())->toBe(ConnectionState::Connected);

    it_await($takeover->disconnect());
});

it('receives QoS 1 messages queued while its persistent session was offline', function () {
    $clientId = 'harken-it-'.bin2hex(random_bytes(5));
    $topic = it_topic('offline');
    $persistent = ['clientId' => $clientId, 'cleanSession' => false, 'sessionExpiry' => 60];

    $first = it_connection(ProtocolVersion::V5_0, $persistent);
    $first->subscribe([$topic => 1]);
    it_await($first->connect());
    it_await($first->disconnect());

    $publisher = it_connection(ProtocolVersion::V5_0);
    it_await($publisher->connect());
    it_await(all([$publisher->publish($topic, 'one', 1), $publisher->publish($topic, 'two', 1)]));

    $second = it_connection(ProtocolVersion::V5_0, $persistent);
    $messages = [];
    $done = new Deferred;
    $second->onMessage(function (Message $message) use (&$messages, $done) {
        $messages[] = $message->payload;
        if (count($messages) === 2) {
            $done->resolve(null);
        }
    });
    $second->subscribe([$topic => 1]);
    it_await($second->connect());
    it_await($done->promise());

    expect($second->sessionPresent())->toBeTrue()
        ->and($messages)->toBe(['one', 'two']);

    // End the session so it does not linger on the broker.
    $cleanup = it_connection(ProtocolVersion::V5_0, ['clientId' => $clientId]);
    it_await($cleanup->connect());
    it_await(all([$second->disconnect(), $cleanup->disconnect(), $publisher->disconnect()]));
});

it('connects over TLS with a private CA', function (ProtocolVersion $protocol) {
    $ca = dirname(__DIR__, 2).'/docker/certs/ca.crt';

    if (! is_file($ca)) {
        $this->markTestSkipped('Run docker/certs/generate.sh to create the test CA.');
    }

    $connection = it_connection($protocol, ['endpoint' => new Endpoint(
        (string) getenv('HARKEN_TEST_BROKER_HOST'),
        (int) (getenv('HARKEN_TEST_BROKER_TLS_PORT') ?: 8883),
        new TlsOptions(caFile: $ca),
    )]);
    $topic = it_topic("tls-{$protocol->value}");
    $received = new Deferred;
    $connection->onMessage(fn (Message $message) => $received->resolve($message->payload));
    $connection->subscribe([$topic => 1]);

    it_await($connection->connect());
    it_await($connection->publish($topic, 'over tls', 1));

    expect(it_await($received->promise()))->toBe('over tls');

    it_await($connection->disconnect());
})->with('protocols');
