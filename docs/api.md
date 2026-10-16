# Harken public API

This document fixes the public surface of Harken before the implementation fills it in. Anything not listed here is internal and may change without notice.

Status legend: **stable** (signature fixed for v0.1), **draft** (shape agreed, details may move), **planned** (not in the skeleton yet).

## Registration — `routes/harken.php`

```php
use App\Mqtt\StatusHandler;
use App\Mqtt\TemperatureHandler;
use Whatef\Harken\Facades\Harken;

// Only the elected leader replica subscribes (the default).
Harken::topic('sensors/{id}/temperature', TemperatureHandler::class)->qos(1);

Harken::topic('sensors/{id}/status', StatusHandler::class)
    ->qos(1)
    ->shared('ingest')   // subscribes to $share/ingest/sensors/+/status
    ->queued();          // runs the handler on a Laravel queue worker

Harken::topic('config/changed', RefreshCache::class)->broadcast(); // every replica

Harken::every(5, fn () => /* sweep pending work */ null);
```

| Method | Signature | Status |
|---|---|---|
| `Harken::topic` | `topic(string $pattern, string\|callable $handler): Route` | stable |
| `Route::qos` | `qos(int $qos): static` — 0 or 1 (QoS 2 is rejected until supported) | stable |
| `Route::queued` | `queued(): static` — handler must be a class name | stable |
| `Route::leader` | `leader(): static` — only the elected leader replica subscribes (default) | stable |
| `Route::shared` | `shared(string $group): static` — MQTT shared subscription group | stable |
| `Route::broadcast` | `broadcast(): static` — every replica subscribes | stable |
| `Harken::every` | `every(int\|float $seconds, callable $callback): void` | stable |

### Topic patterns

- A level written as `{name}` matches exactly one topic level and is exposed as a route parameter. It is subscribed as `+`.
- Raw MQTT wildcards are allowed: `+` (one level, unnamed) and `#` (any remaining levels, last position only; `a/#` also matches `a`).
- Patterns must not start with `$share/`; use `->shared()` instead. Wildcard first levels never match topics that start with `$` (MQTT 3.1.1 §4.7.2).
- When several routes with the same distribution match one topic, the first registered route wins. Overlapping subscriptions may make the broker deliver a message more than once, so avoid overlaps.

### Distribution across replicas

Running several replicas of `harken:start` keeps the runtime available through deployments and crashes. Each route chooses how its messages are spread across them:

| Distribution | Who receives | Fits | Cost |
|---|---|---|---|
| `leader()` (default) | the elected leader only; the others stand by | handlers that keep per-source state in memory (replay checks, ordering) | one process carries the whole inbound load; a few seconds without a subscriber when the leader changes |
| `shared('group')` | one replica per message (`$share/group/…`) | handlers where every message is independent | messages from one source are handled by different processes |
| `broadcast()` | every replica | refreshing a per-process cache or setting | the work is repeated on every replica |

- Publishing is not affected: every replica publishes, whatever the distribution of its routes.
- **Shared.** Brokers can pin a source to one member (EMQX `hash_clientid` / `hash_topic`), but the assignment changes whenever the number of replicas changes, and EMQX has a single strategy for the whole broker. Harken does not rely on it.
- A message that matches a leader route and a broadcast route arrives on both connections of the leader replica; each route's handler runs once.

## Handlers

A handler is an invokable class resolved from the container, or a closure. It receives one `Message` and returns nothing. It is a plain class, so tests can call it directly without a broker.

```php
use Whatef\Harken\Runtime\Message;

final class TemperatureHandler
{
    public function __construct(private SensorReadings $readings) {}

    public function __invoke(Message $message): void
    {
        $this->readings->record($message->param('id'), $message->payload);
    }
}
```

Handlers that are **not** queued run inline on the event loop and must not block. Inside them, `await()` and other Fiber suspension are not allowed. Handlers that take longer than `harken.handlers.slow_threshold_ms` are logged.

Ordering: inline handlers of one connection run one at a time, in the order the broker delivered the messages, so a leader route sees every message in order in a single process. `->queued()` handlers run on queue workers and may run out of order and in parallel; keep order-sensitive or per-source state in an inline handler and queue only the work that follows from it.

### `Whatef\Harken\Runtime\Message` — stable

```php
final readonly class Message
{
    public function __construct(
        public string $topic,
        public string $payload,          // raw bytes, never re-encoded
        public int $qos = 0,
        public bool $retain = false,
        public array $params = [],       // array<string, string> route parameters
        public Properties $properties = new Properties, // MQTT 5 message properties (empty on 3.1.1)
    ) {}

    public function param(string $name, ?string $default = null): ?string;
}
```

The payload stays byte-for-byte identical through queue serialization.

### `Whatef\Harken\Protocol\Properties` — stable

MQTT 5 message properties, used for inbound messages and as the last argument of `publish()`. Values are validated on construction (`InvalidArgumentException`). MQTT 3.1.1 cannot carry properties: on a 3.1.1 connection they are empty inbound and dropped outbound.

```php
final readonly class Properties
{
    public function __construct(
        public ?string $contentType = null,
        public ?string $responseTopic = null,         // topic name, no wildcards
        public ?string $correlationData = null,       // binary
        public ?int $messageExpiryInterval = null,    // seconds, 0..4294967295
        public bool $payloadFormatIndicator = false,  // true: the payload is UTF-8 text
        array $userProperties = [],                   // array<string, string|list<string>>
        public array $subscriptionIdentifiers = [],   // list<int>, set by the broker on inbound messages
    ) {}

    public array $userProperties;                     // a repeated name maps to a list of its values
    public function userProperty(string $name): ?string;  // first value
    public function isEmpty(): bool;
}
```

```php
use Whatef\Harken\Protocol\Properties;

Harken::publish('cmd/door/1', '{"open":true}', qos: 1, properties: new Properties(
    contentType: 'application/json',
    messageExpiryInterval: 30,
    userProperties: ['trace-id' => $traceId],
));
```

User properties keep every value of a repeated name, in order. The relative order of different names is not kept.
## Publishing

| Method | Signature | Status |
|---|---|---|
| `Harken::publish` | `publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface` | stable |
| `Harken::clearRetained` | `clearRetained(string $topic): PromiseInterface` — empty payload, retain, QoS 1 | stable |

`PromiseInterface` is `React\Promise\PromiseInterface`.

- **Inside the runtime** (handlers, timers, sources): the message goes out on the runtime's own connection. For QoS 1 the promise resolves only after the PUBACK. On MQTT 5 it rejects when the PUBACK carries an error reason code (for example 0x87 not authorized).
- **Outside the runtime** (HTTP requests, queued jobs, `->queued()` handlers): the message is appended to a Redis stream and the runtime publishes it. The promise resolves once the stream entry is written (`XADD`), not when the broker acknowledges. Delivery is at least once.
- Publish priority (urgent / normal streams) is **planned**.

## Testing

| Method | Signature | Status |
|---|---|---|
| `Harken::fake` | `fake(): HarkenFake` | stable |
| `Harken::assertPublished` | `assertPublished(string $topic, ?callable $callback = null): void` — callback receives `(string $payload, int $qos, bool $retain, Properties $properties)` and returns bool | stable |
| `Harken::assertNotPublished` | `assertNotPublished(string $topic, ?callable $callback = null): void` | stable |
| `Harken::assertNothingPublished` | `assertNothingPublished(): void` | stable |
| `Harken::simulate` | `simulate(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): void` | stable |

`simulate()` sends the message through the same router the runtime uses:
- Inline handlers run immediately. Their exceptions reach the test instead of being isolated.
- `->queued()` handlers are dispatched as jobs, so `Queue::fake()` / `Bus::fake()` can assert on them.
- A topic that matches no route is ignored, as in the runtime.

## Protocol version

Each connection in `config/harken.php` sets `protocol` (`HARKEN_PROTOCOL`): `5.0` (default) or `3.1.1`. The client picks the version when it connects and the broker serves every client in its own version, so devices on 3.1.1 and Harken on 5.0 can share a broker. There is no automatic fallback: a broker without MQTT 5 refuses the connection at startup, and the error names the setting to change.

## Artisan

| Command | Options | Status |
|---|---|---|
| `harken:start` | `--connection=` `--debug` `--memory=` (MB) `--max-time=` (seconds) | draft |
| `harken:restart` | — | draft |
| `harken:status` | — | planned |

## Planned

- External event sources (PostgreSQL `LISTEN/NOTIFY`): registration API after spike 04.
- Per-route acknowledgement policy for QoS 1 (`ack after handler`).
- Payload decoders (opt-in; raw bytes stay the default).
