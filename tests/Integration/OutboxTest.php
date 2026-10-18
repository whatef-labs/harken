<?php

declare(strict_types=1);

/*
 * The Redis Streams outbox against a real Redis server.
 *
 *   HARKEN_TEST_REDIS_HOST=127.0.0.1 vendor/bin/pest --group=integration
 */

use Illuminate\Support\Facades\Redis;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Laravel\Publishing\OutboxUnavailable;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionClosed;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Connection\PublishRejected;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Outbox\OutboxObserver;
use Whatef\Harken\Runtime\Outbox\RedisStreamsOutbox;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Runtime\Redis\RedisClient;
use Whatef\Harken\Runtime\Redis\RedisEndpoint;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\reject;
use function React\Promise\resolve;
use function React\Promise\Timer\timeout;

beforeEach(function () {
    if (! getenv('HARKEN_TEST_REDIS_HOST')) {
        $this->markTestSkipped('Set HARKEN_TEST_REDIS_HOST to run outbox tests.');
    }
});

afterEach(function () {
    foreach (outbox_opened() as $outbox) {
        await($outbox->stop(0.0));
    }

    foreach (outbox_opened() as $outbox) {
        $redis = new RedisClient($outbox->endpoint, Loop::get());
        await($redis->call('del', ...$outbox->streams, ...array_map(RedisStreamsOutbox::trimmedKey(...), $outbox->streams)));
        $redis->close();
    }

    // An open connection keeps the loop, and so the test process, alive.
    foreach (outbox_clients() as $client) {
        $client->close();
    }

    outbox_opened(reset: true);
    outbox_clients(reset: true);
});

/**
 * @return list<RedisClient>
 */
function outbox_clients(?RedisClient $client = null, bool $reset = false): array
{
    static $clients = [];

    if ($reset) {
        $clients = [];
    }

    if ($client !== null) {
        $clients[] = $client;
    }

    return $clients;
}

/**
 * @return list<RedisStreamsOutbox>
 */
function outbox_opened(?RedisStreamsOutbox $outbox = null, bool $reset = false): array
{
    static $opened = [];

    if ($reset) {
        $opened = [];
    }

    if ($outbox !== null) {
        $opened[] = $outbox;
    }

    return $opened;
}

final class RecordingPublisher implements Publisher
{
    /** @var list<OutboxMessage> */
    public array $published = [];

    /** @var list<Deferred<null>> */
    public array $held = [];

    /** @var (Closure(OutboxMessage): PromiseInterface<mixed>)|null */
    public ?Closure $answer = null;

    public bool $hold = false;

    /** @var list<string> Topics published as urgent. */
    public array $urgent = [];

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null, bool $urgent = false): PromiseInterface
    {
        $message = new OutboxMessage($topic, $payload, $qos, $retain, null, $properties ?? new Properties);
        $this->published[] = $message;

        if ($urgent) {
            $this->urgent[] = $topic;
        }

        if ($this->answer !== null) {
            return ($this->answer)($message);
        }

        if ($this->hold) {
            $deferred = new Deferred;
            $this->held[] = $deferred;

            return $deferred->promise();
        }

        return resolve(null);
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return array_map(static fn (OutboxMessage $m): string => $m->topic, $this->published);
    }
}

final class RecordingOutboxObserver implements OutboxObserver
{
    /** @var list<string> */
    public array $events = [];

    public function published(string $stream, OutboxMessage $message, ?float $latencyMs): void
    {
        $this->events[] = "published {$message->topic}";
    }

    public function dropped(string $stream, string $id, ?OutboxMessage $message, Throwable $reason): void
    {
        $this->events[] = 'dropped '.($message->topic ?? 'malformed');
    }

    public function expired(string $stream, OutboxMessage $message): void
    {
        $this->events[] = "expired {$message->topic}";
    }

    public function trimmed(string $stream, int $count): void
    {
        $this->events[] = "trimmed {$count}";
    }

    public function pruned(string $stream, string $consumer): void
    {
        $this->events[] = "pruned {$consumer}";
    }

    public function error(Throwable $error): void
    {
        $this->events[] = 'error '.$error::class;
    }
}

/**
 * @return array{RedisStreamsOutbox, RedisClient, string, string} Outbox, a client to inspect Redis, urgent and normal keys.
 */
function outbox(string $consumer = 'c1', int $batch = 100, int $claimIdleMs = 30000, ?OutboxObserver $observer = null, ?array $streams = null, ?int $pruneIdleMs = null): array
{
    static $run = null;
    $run ??= bin2hex(random_bytes(4));
    $endpoint = new RedisEndpoint((string) getenv('HARKEN_TEST_REDIS_HOST'), (int) (getenv('HARKEN_TEST_REDIS_PORT') ?: 6379));
    $streams ??= ["harken-it:{$run}:".test()->name().':urgent', "harken-it:{$run}:".test()->name().':normal'];
    $outbox = new RedisStreamsOutbox($endpoint, $streams, 'harken', $consumer, 200, $batch, $claimIdleMs, null, $observer, $pruneIdleMs);
    outbox_opened($outbox);

    $redis = new RedisClient($endpoint, Loop::get());
    outbox_clients($redis);

    return [$outbox, $redis, ...$streams];
}

function outbox_add(RedisClient $redis, string $stream, OutboxMessage $message): string
{
    $args = [];

    foreach ($message->toFields() as $field => $value) {
        $args[] = $field;
        $args[] = $value;
    }

    return await($redis->call('xadd', $stream, '*', ...$args));
}

function outbox_wait(callable $condition, float $seconds = 5.0): void
{
    $deadline = microtime(true) + $seconds;

    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the outbox.');
        }

        delay(0.01);
    }
}

function outbox_pending(RedisClient $redis, string $stream): int
{
    return (int) (await($redis->call('xpending', $stream, 'harken'))[0] ?? 0);
}

it('publishes entries in order and removes them once acknowledged', function () {
    [$outbox, $redis, , $normal] = outbox();
    $properties = new Properties(contentType: 'application/json', userProperties: ['trace' => 't-1']);
    outbox_add($redis, $normal, new OutboxMessage('cmd/1', "\x00\xFFbinary", 1, true, properties: $properties));
    outbox_add($redis, $normal, new OutboxMessage('cmd/2', 'two'));
    $publisher = new RecordingPublisher;

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => count($publisher->published) === 2);
    delay(0.1);

    expect($publisher->topics())->toBe(['cmd/1', 'cmd/2'])
        ->and($publisher->published[0]->payload)->toBe("\x00\xFFbinary")
        ->and($publisher->published[0]->qos)->toBe(1)
        ->and($publisher->published[0]->retain)->toBeTrue()
        ->and($publisher->published[0]->properties)->toEqual($properties)
        ->and(await($redis->call('xlen', $normal)))->toBe(0)
        ->and(outbox_pending($redis, $normal))->toBe(0);
});

it('publishes entries written before any runtime created the group', function () {
    [$outbox, $redis, , $normal] = outbox();
    outbox_add($redis, $normal, new OutboxMessage('cmd/early', 'x'));
    $publisher = new RecordingPublisher;

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => $publisher->published !== []);

    expect($publisher->topics())->toBe(['cmd/early']);
});

it('serves the urgent stream before a normal backlog', function () {
    [$outbox, $redis, $urgent, $normal] = outbox(batch: 10);

    for ($i = 0; $i < 50; $i++) {
        outbox_add($redis, $normal, new OutboxMessage("bulk/{$i}", 'x'));
    }
    outbox_add($redis, $urgent, new OutboxMessage('door/open', 'x', 1));
    $publisher = new RecordingPublisher;

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => count($publisher->published) === 51);

    expect($publisher->topics()[0])->toBe('door/open')
        ->and($publisher->urgent)->toBe(['door/open']);
});

it('keeps at most one batch in flight', function () {
    [$outbox, $redis, , $normal] = outbox(batch: 10);

    for ($i = 0; $i < 25; $i++) {
        outbox_add($redis, $normal, new OutboxMessage("cmd/{$i}", 'x', 1));
    }
    $publisher = new RecordingPublisher;
    $publisher->hold = true;

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => count($publisher->published) === 10);
    delay(0.3);

    expect($publisher->published)->toHaveCount(10)
        ->and($outbox->inFlightCount())->toBe(10);

    foreach ($publisher->held as $deferred) {
        $deferred->resolve(null);
    }
    outbox_wait(fn () => count($publisher->published) === 20);

    expect($outbox->inFlightCount())->toBe(10);
});

it('drops entries the broker refuses for good and keeps the rest pending', function () {
    $observer = new RecordingOutboxObserver;
    [$outbox, $redis, , $normal] = outbox(observer: $observer);
    outbox_add($redis, $normal, new OutboxMessage('cmd/denied', 'x', 1));
    outbox_add($redis, $normal, new OutboxMessage('cmd/offline', 'x', 1));
    $redis->call('xadd', $normal, '*', 'garbage', '1');
    $publisher = new RecordingPublisher;
    $publisher->answer = fn (OutboxMessage $m): PromiseInterface => $m->topic === 'cmd/denied'
        ? reject(PublishRejected::byBroker($m->topic, ReasonCode::NotAuthorized))
        : reject(ConnectionClosed::stopped());

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => count($publisher->published) === 2 && count($observer->events) === 3);
    delay(0.1);

    expect($observer->events)->toContain('dropped cmd/denied', 'dropped malformed', 'error '.ConnectionClosed::class)
        ->and(await($redis->call('xlen', $normal)))->toBe(1)
        ->and(outbox_pending($redis, $normal))->toBe(1);
});

it('removes entries whose message expiry ran out and sends the others with the time left', function () {
    $observer = new RecordingOutboxObserver;
    [$outbox, $redis, , $normal] = outbox(observer: $observer);
    $now = (int) (microtime(true) * 1000);
    outbox_add($redis, $normal, new OutboxMessage('cmd/stale', 'x', 1, false, $now - 5000, new Properties(messageExpiryInterval: 3)));
    outbox_add($redis, $normal, new OutboxMessage('cmd/fresh', 'x', 1, false, $now - 1500, new Properties(contentType: 'text/plain', messageExpiryInterval: 10)));
    outbox_add($redis, $normal, new OutboxMessage('cmd/lasting', 'x', 1, false, $now - 5000));
    $publisher = new RecordingPublisher;

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => count($publisher->published) === 2 && in_array('expired cmd/stale', $observer->events, true));
    delay(0.1);

    expect($publisher->topics())->toBe(['cmd/fresh', 'cmd/lasting'])
        ->and($publisher->published[0]->properties->messageExpiryInterval)->toBe(9)
        ->and($publisher->published[0]->properties->contentType)->toBe('text/plain')
        ->and($publisher->published[1]->properties->messageExpiryInterval)->toBeNull()
        ->and(await($redis->call('xlen', $normal)))->toBe(0)
        ->and(outbox_pending($redis, $normal))->toBe(0);
});

it('takes over entries a crashed replica left pending', function () {
    [$crashed, $redis, $urgent, $normal] = outbox(consumer: 'crashed');
    outbox_add($redis, $normal, new OutboxMessage('cmd/stranded', 'x', 1));
    $lost = new RecordingPublisher;
    $lost->hold = true; // never acknowledged
    $crashed->start(Loop::get(), $lost);
    outbox_wait(fn () => $lost->published !== []);
    await($crashed->stop(0.0)); // gone without XACK

    expect(outbox_pending($redis, $normal))->toBe(1);

    [$survivor] = outbox(consumer: 'survivor', claimIdleMs: 300, streams: [$urgent, $normal]);
    $publisher = new RecordingPublisher;
    $survivor->start(Loop::get(), $publisher);
    outbox_wait(fn () => $publisher->published !== [], 3.0);
    delay(0.1);

    expect($publisher->topics())->toBe(['cmd/stranded'])
        ->and(outbox_pending($redis, $normal))->toBe(0);
});

it('removes consumers that stopped reading with nothing pending, and keeps those with pending entries', function () {
    $observer = new RecordingOutboxObserver;
    [$outbox, $redis, $urgent, $normal] = outbox(observer: $observer, pruneIdleMs: 300);
    outbox_add($redis, $normal, new OutboxMessage('cmd/stuck', 'x', 1));

    foreach ([$urgent, $normal] as $stream) {
        await($redis->call('xgroup', 'CREATE', $stream, 'harken', '0', 'MKSTREAM'));
        await($redis->call('xgroup', 'CREATECONSUMER', $stream, 'harken', 'gone'));
    }

    await($redis->call('xreadgroup', 'GROUP', 'harken', 'stuck', 'COUNT', 1, 'STREAMS', $normal, '>')); // never acknowledged
    delay(0.4);

    $outbox->start(Loop::get(), new RecordingPublisher);
    outbox_wait(fn () => count(array_filter($observer->events, fn (string $e): bool => $e === 'pruned gone')) === 2);
    delay(0.1);
    $names = fn (string $stream): array => array_map(fn (array $c): string => $c[1], await($redis->call('xinfo', 'CONSUMERS', $stream, 'harken')));

    expect($names($normal))->toBe(['c1', 'stuck'])
        ->and($names($urgent))->toBe(['c1'])
        ->and(outbox_pending($redis, $normal))->toBe(1);
});

it('lets entries in flight finish when it stops', function () {
    [$outbox, $redis, , $normal] = outbox();
    outbox_add($redis, $normal, new OutboxMessage('cmd/slow', 'x', 1));
    $publisher = new RecordingPublisher;
    $publisher->answer = fn (): PromiseInterface => timeout(new Deferred()->promise(), 0.2)->then(null, fn () => null);
    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => $publisher->published !== []);

    $started = microtime(true);
    await($outbox->stop(5.0));
    delay(0.1);

    expect(microtime(true) - $started)->toBeLessThan(1.0)
        ->and(await($redis->call('xlen', $normal)))->toBe(0);
});

it('queues publishes made outside the runtime in the application Redis, with its key prefix', function () {
    config()->set('database.redis.default.host', getenv('HARKEN_TEST_REDIS_HOST'));
    config()->set('database.redis.default.port', getenv('HARKEN_TEST_REDIS_PORT') ?: 6379);
    config()->set('database.redis.options.prefix', 'app_it_');
    config()->set('harken.outbox.streams.normal', $stream = 'harken-it:'.bin2hex(random_bytes(4)).':delegate');
    config()->set('harken.outbox.streams.urgent', "{$stream}:urgent");

    $id = await(Harken::publish('cmd/door', "open\x00", 1, false, new Properties(contentType: 'text/plain')));

    [$outbox] = outbox(streams: ["app_it_{$stream}:urgent", "app_it_{$stream}"]);
    $publisher = new RecordingPublisher;
    $outbox->start(Loop::get(), $publisher);
    outbox_wait(fn () => $publisher->published !== []);

    $urgentId = await(Harken::urgent()->publish('cmd/alarm', 'now', 1));
    outbox_wait(fn () => count($publisher->published) === 2);

    expect($urgentId)->toMatch('/^\d+-\d+$/')
        ->and($publisher->urgent)->toBe(['cmd/alarm'])
        ->and($id)->toMatch('/^\d+-\d+$/')
        ->and($publisher->published[0]->topic)->toBe('cmd/door')
        ->and($publisher->published[0]->payload)->toBe("open\x00")
        ->and($publisher->published[0]->properties->contentType)->toBe('text/plain');

    Redis::connection()->executeRaw(['DEL', "app_it_{$stream}", "app_it_{$stream}:urgent"]);
});

it('trims the oldest entries beyond max_length and reports the count once', function () {
    config()->set('database.redis.default.host', getenv('HARKEN_TEST_REDIS_HOST'));
    config()->set('database.redis.default.port', getenv('HARKEN_TEST_REDIS_PORT') ?: 6379);
    config()->set('database.redis.options.prefix', 'app_it_');
    config()->set('harken.outbox.streams.normal', $stream = 'harken-it:'.bin2hex(random_bytes(4)).':capped');
    config()->set('harken.outbox.streams.urgent', "{$stream}:urgent");
    config()->set('harken.outbox.max_length', 10);

    for ($i = 0; $i < 300; $i++) {
        Harken::publish("cmd/{$i}", 'x');
    }

    $streams = ["app_it_{$stream}:urgent", "app_it_{$stream}"];
    $observer = new RecordingOutboxObserver;
    [$first, $redis] = outbox(observer: $observer, streams: $streams);
    [$second] = outbox(consumer: 'c2', observer: $observer, streams: $streams);
    $kept = await($redis->call('xlen', $streams[1]));

    expect($kept)->toBeGreaterThanOrEqual(10)->toBeLessThan(300)
        ->and((int) await($redis->call('get', RedisStreamsOutbox::trimmedKey($streams[1]))))->toBe(300 - $kept);

    $publisher = new RecordingPublisher;
    $first->start(Loop::get(), $publisher);
    $second->start(Loop::get(), $publisher);
    outbox_wait(fn () => count($publisher->published) === $kept);
    $first->collectTrimmed();
    $second->collectTrimmed();
    $trims = fn (): array => array_values(array_filter($observer->events, fn (string $e): bool => str_starts_with($e, 'trimmed')));
    outbox_wait(fn () => $trims() !== []);
    delay(0.1);

    $topics = $publisher->topics();
    sort($topics, SORT_NATURAL);

    expect($trims())->toBe(['trimmed '.(300 - $kept)])
        ->and($topics)->toBe(array_map(fn (int $i): string => "cmd/{$i}", range(300 - $kept, 299)))
        ->and(await($redis->call('exists', RedisStreamsOutbox::trimmedKey($streams[1]))))->toBe(0);
});

it('throws when the outbox cannot store a publish', function () {
    config()->set('database.redis.default.host', '127.0.0.1');
    config()->set('database.redis.default.port', 1);

    Harken::publish('cmd/door', 'open');
})->throws(OutboxUnavailable::class, 'Cannot queue the publish to [cmd/door] in the Harken outbox');

it('publishes through a real broker and acknowledges after the PUBACK', function () {
    if (! getenv('HARKEN_TEST_BROKER_HOST')) {
        $this->markTestSkipped('Set HARKEN_TEST_BROKER_HOST as well to publish through a broker.');
    }

    $connect = function (): Connection {
        $connection = new Connection(new ConnectionOptions(
            'default',
            new Endpoint((string) getenv('HARKEN_TEST_BROKER_HOST'), (int) (getenv('HARKEN_TEST_BROKER_PORT') ?: 1883)),
            'harken-it-'.bin2hex(random_bytes(5)),
            new ExponentialBackoff,
        ), new ReactSocketTransport(Loop::get()), ProtocolVersion::V5_0->codec(), Loop::get());

        return $connection;
    };
    $topic = 'harken-it/'.bin2hex(random_bytes(4)).'/outbox';
    $received = [];
    $subscriber = $connect();
    $subscriber->subscribe([$topic => 1]);
    $subscriber->onMessage(function (Message $message) use (&$received) {
        $received[] = $message->payload;
    });
    $publisher = $connect();
    await(timeout($subscriber->connect(), 5));
    await(timeout($publisher->connect(), 5));

    [$outbox, $redis, , $normal] = outbox();

    for ($i = 0; $i < 200; $i++) {
        outbox_add($redis, $normal, new OutboxMessage($topic, (string) $i, 1));
    }

    $outbox->start(Loop::get(), $publisher);
    outbox_wait(function () use (&$received) {
        return count($received) === 200;
    });
    delay(0.1);

    expect($received)->toBe(array_map('strval', range(0, 199)))
        ->and(await($redis->call('xlen', $normal)))->toBe(0)
        ->and(outbox_pending($redis, $normal))->toBe(0);

    await($outbox->stop(1.0));
    $subscriber->disconnect();
    $publisher->disconnect();
});
