# Harken public API

This document fixes the public surface of Harken before the implementation fills it in. Anything not listed here is internal and may change without notice.

Status legend: **stable** (signature fixed for v0.1), **draft** (shape agreed, details may move), **planned** (not in the skeleton yet).

## Registration — `routes/harken.php`

```php
use App\Mqtt\StatusHandler;
use App\Mqtt\TemperatureHandler;
use Whatef\Harken\Facades\Harken;

Harken::topic('sensors/{id}/temperature', TemperatureHandler::class)->qos(1);

Harken::topic('sensors/{id}/status', StatusHandler::class)
    ->qos(1)
    ->shared('ingest')   // subscribes to $share/ingest/sensors/+/status
    ->queued();          // runs the handler on a Laravel queue worker

Harken::every(5, fn () => /* sweep pending work */ null);
```

| Method | Signature | Status |
|---|---|---|
| `Harken::topic` | `topic(string $pattern, string\|callable $handler): Route` | stable |
| `Route::qos` | `qos(int $qos): static` — 0 or 1 (QoS 2 is rejected until supported) | stable |
| `Route::queued` | `queued(): static` — handler must be a class name | stable |
| `Route::shared` | `shared(string $group): static` — MQTT shared subscription group | stable |
| `Harken::every` | `every(int\|float $seconds, callable $callback): void` | stable |

### Topic patterns

- A level written as `{name}` matches exactly one topic level and is exposed as a route parameter. It is subscribed as `+`.
- Raw MQTT wildcards are allowed: `+` (one level, unnamed) and `#` (any remaining levels, last position only; `a/#` also matches `a`).
- Patterns must not start with `$share/`; use `->shared()` instead. Wildcard first levels never match topics that start with `$` (MQTT 3.1.1 §4.7.2).
- When several routes match one topic, the first registered route wins. Overlapping subscriptions may make the broker deliver a message more than once, so avoid overlaps.

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
        public array $properties = [],   // array<string, mixed>, MQTT 5 properties (empty on 3.1.1)
    ) {}

    public function param(string $name, ?string $default = null): ?string;
}
```

The payload stays byte-for-byte identical through queue serialization.

## Publishing

| Method | Signature | Status |
|---|---|---|
| `Harken::publish` | `publish(string $topic, string $payload, int $qos = 0, bool $retain = false): PromiseInterface` | stable |
| `Harken::clearRetained` | `clearRetained(string $topic): PromiseInterface` — empty payload, retain, QoS 1 | stable |

`PromiseInterface` is `React\Promise\PromiseInterface`.

- **Inside the runtime** (handlers, timers, sources): the message goes out on the runtime's own connection. For QoS 1 the promise resolves only after the PUBACK.
- **Outside the runtime** (HTTP requests, queued jobs, `->queued()` handlers): the message is appended to a Redis stream and the runtime publishes it. The promise resolves once the stream entry is written (`XADD`), not when the broker acknowledges. Delivery is at least once.
- Publish priority (urgent / normal streams) is **planned**.

## Testing

| Method | Signature | Status |
|---|---|---|
| `Harken::fake` | `fake(): HarkenFake` | stable |
| `Harken::assertPublished` | `assertPublished(string $topic, ?callable $callback = null): void` — callback receives `(string $payload, int $qos, bool $retain)` and returns bool | stable |
| `Harken::assertNotPublished` | `assertNotPublished(string $topic, ?callable $callback = null): void` | draft |
| `Harken::assertNothingPublished` | `assertNothingPublished(): void` | draft |
| `Harken::simulate` | `simulate(string $topic, string $payload, int $qos = 0, bool $retain = false): void` | stable |

`simulate()` sends the message through the same router the runtime uses:
- Inline handlers run immediately. Their exceptions reach the test instead of being isolated.
- `->queued()` handlers are dispatched as jobs, so `Queue::fake()` / `Bus::fake()` can assert on them.
- A topic that matches no route is ignored, as in the runtime.

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
