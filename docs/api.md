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

Harken::every(5, fn () => /* sweep pending work */ null);           // the leader replica only
Harken::every(60, fn () => /* refresh a local cache */ null)->broadcast(); // every replica
```

| Method | Signature | Status |
|---|---|---|
| `Harken::topic` | `topic(string $pattern, string\|callable $handler): Route` | stable |
| `Route::qos` | `qos(int $qos): static` — 0 or 1 (QoS 2 is rejected until supported) | stable |
| `Route::queued` | `queued(): static` — handler must be a class name | stable |
| `Route::leader` | `leader(): static` — only the elected leader replica subscribes (default) | stable |
| `Route::shared` | `shared(string $group): static` — MQTT shared subscription group | stable |
| `Route::broadcast` | `broadcast(): static` — every replica subscribes | stable |
| `Harken::every` | `every(int\|float $seconds, callable $callback): Task` — `->leader()` (default), `->broadcast()` | stable |
| `Harken::listen` | `listen(string $channel, string\|callable $handler): NotificationRoute` — PostgreSQL NOTIFY; `->leader()` (default), `->broadcast()`, `->queued()` | stable |

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
- The same rule applies to `Harken::every()` timers and `Harken::listen()` channels: by default only the leader runs them, so a periodic sweep of pending work runs once, not once per replica; `->broadcast()` runs them on every replica. A leader timer starts when the replica becomes leader and stops when it stops leading, and a run is skipped once its lease has run out.
- **Leader.** The leader holds a lease (Redis by default) and opens a second connection with a fixed client id, clean start off and a session expiry, so the broker keeps queuing QoS 1 messages while the leader changes and the next leader receives them. Two processes can never receive on that client id at once: the broker closes the older connection, and a leader whose lease ran out closes its connection before handling another message. QoS 0 messages sent while no leader is connected are lost. The broker bounds the queue of a disconnected session (EMQX: `mqtt.max_mqueue_len`, 1000 by default) and drops QoS 1 messages beyond it without telling anyone, so size it for the inbound rate times the takeover time.
  - Settings live under `leader` in each connection of `config/harken.php`: the lock (`redis` by default, `pgsql` for a PostgreSQL 14+ advisory lock with ext-pgsql, or `none` for a single replica), the lease (5 s), the retry interval (0.25 s) and the session expiry (300 s). The leader client id defaults to `{client_id_prefix}-leader`; the prefix defaults to the application name.
  - A standby replica takes over within the lease after a crash, and within the retry interval after a graceful stop, because a stopping leader releases the lock at once.
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

Cost: pushing the job to the queue happens on the event loop and waits for the queue's answer, which [measured](benchmarks.md#queued-handlers-x-05) 110 – 170 µs per message against about 28 µs for a light inline handler. Queue slow work; handle light, frequent messages inline. A handler class that keeps no per-message state can be bound as a singleton in the container, so it is not built again for every message.

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
## PostgreSQL notifications

```php
use Whatef\Harken\Runtime\Sources\Notification;

Harken::listen('outbox_ready', SweepPending::class);               // the leader replica only
Harken::listen('config_changed', RefreshCache::class)->broadcast(); // every replica

final class SweepPending
{
    public function __invoke(Notification $notification): void
    {
        // $notification->channel, $notification->payload
        // $notification->resync: listening (re)started; look for work you may have missed
    }
}
```

- The runtime listens on its own session of the database connection `harken.sources.pgsql.connection` (default `pgsql`). With ext-pgsql the session's socket is watched by the event loop; without it, `Pdo\Pgsql::getNotify()` is polled every `harken.sources.pgsql.poll_interval_ms` (10 ms).
- PostgreSQL sends a NOTIFY to every listening session, so by default only the elected leader listens, and a notification is handled by one process. `->broadcast()` makes every replica listen.
- NOTIFY is a hint, not a queue: notifications sent while nobody listens are lost, and identical ones in one transaction are folded into one. Each time listening starts (process start, reconnect, a new leader), every handler of the channel is called once with `resync` set and an empty payload. A periodic `Harken::every()` sweep covers the same gap.
- Channel names are used as given (`LISTEN "name"`), so they are case-sensitive. Send with `pg_notify('name', payload)` or `NOTIFY "name"`; an unquoted `NOTIFY Name` is folded to lower case.
- `Whatef\Harken\Runtime\Sources\Notification` is a readonly value object: `channel`, `payload`, `resync`.

## Publishing

| Method | Signature | Status |
|---|---|---|
| `Harken::publish` | `publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface` | stable |
| `Harken::clearRetained` | `clearRetained(string $topic): PromiseInterface` — empty payload, retain, QoS 1 | stable |
| `Harken::urgent` | `urgent(): UrgentPublisher` — `->publish(...)` and `->clearRetained(...)` with the same arguments, sent ahead of messages that are not urgent | stable |

`PromiseInterface` is `React\Promise\PromiseInterface`.

- **Inside the runtime** (handlers, timers, sources): the message goes out on the runtime's own connection. For QoS 1 the promise resolves only after the PUBACK. On MQTT 5 it rejects when the PUBACK carries an error reason code (for example 0x87 not authorized).
- **Outside the runtime** (HTTP requests, queued jobs, `->queued()` handlers): the message is appended to a Redis stream (`harken.outbox.redis`, with the connection's key prefix) and a running runtime publishes it. The call is synchronous: it returns an already resolved promise holding the stream entry id once `XADD` succeeded, and throws `Whatef\Harken\Laravel\Publishing\OutboxUnavailable` when Redis could not store it, so a failure cannot go unnoticed in code that ignores the promise. It does not wait for the broker.
- Every runtime replica consumes the outbox as one consumer group, so each message is published by one replica. An entry is removed only after the broker acknowledged it (PUBACK for QoS 1), so delivery is at least once; entries a crashed replica left pending are taken over after `claim_idle_ms`. A message the broker refuses for good (MQTT 5 reason code such as 0x87 not authorized) is dropped and reported rather than retried forever. `harken.outbox.max_rate` paces a large backlog.
- **Message expiry.** The time an entry waits in the outbox counts against its MQTT 5 message expiry interval (`new Properties(messageExpiryInterval: 10)`), the way a broker counts the time a message waits for a subscriber (MQTT 5 section 3.3.2.3.3). An entry whose interval ran out is removed without being published and counted in `harken_outbox_expired_total` and the status; the others go out with the seconds left, rounded up, so the broker keeps counting from when the application published. Use it for commands that are useless a few seconds later, so a backlog built up while no runtime ran does not go out all at once. An interval of 0 has always run out by the time a runtime reads the entry. On an MQTT 3.1.1 connection the property is not sent, but expired entries are still removed. The wait is measured from the publishing host's clock to the runtime's, so keep the clocks synchronized (NTP).
- **Urgent messages.** `Harken::urgent()->publish('cmd/door/1', $payload, qos: 1)` goes to the urgent outbox stream, which every runtime drains before the normal one, so a command is not stuck behind a bulk backlog. Inside the runtime it goes to the front of the connection's queue of messages waiting for the broker's Receive Maximum.
- **Length limit.** Without a runtime consuming it, the outbox grows until Redis is full, and on a `noeviction` instance shared with sessions and queues that stops their writes as well. `harken.outbox.max_length` (`HARKEN_OUTBOX_MAX_LENGTH`, empty for no limit) caps each stream: the publish trims the stream in the same atomic step (`XTRIM MAXLEN ~`), so the **oldest entries, which have not been published, are lost**. Redis trims whole nodes, so a stream can hold somewhat more than the limit. A runtime counts the trimmed entries in `harken_outbox_trimmed_total` and the status, and logs a warning; entries trimmed while no runtime ran are reported once one starts. An entry trimmed while a runtime was already publishing it still goes out, so the count is an upper bound.

## Testing

| Method | Signature | Status |
|---|---|---|
| `Harken::fake` | `fake(): HarkenFake` | stable |
| `Harken::assertPublished` | `assertPublished(string $topic, ?callable $callback = null): void` — callback receives `(string $payload, int $qos, bool $retain, Properties $properties, bool $urgent)` and returns bool | stable |
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
| `harken:start` | `--connection=` `--debug` (print every MQTT packet) `--memory=` (MB) `--max-time=` (seconds) | stable |
| `harken:restart` | — | stable |
| `harken:status` | `--all` (every host) `--json`; exits 1 when a runtime on this host is unhealthy | stable |

## Running

`harken:start` runs in the foreground; run it under a process manager or as a container, next to `queue:work` or Horizon and Reverb:

```yaml
harken:
  image: my-app
  command: php artisan harken:start
  stop_grace_period: 15s
  healthcheck:
    test: ["CMD", "php", "artisan", "harken:status"]
    interval: 10s
```

- **Graceful stop.** SIGTERM, SIGINT or SIGTSTP (with ext-pcntl), Ctrl+C or Ctrl+Break on Windows, `harken:restart`, `--memory` and `--max-time` stop the runtime in this order, within `harken.shutdown.grace` seconds: stop timers and NOTIFY sources, hold inbound messages (unacknowledged QoS 1 messages are delivered again after the connection closes), stop leading (DISCONNECT and release the lock, so a standby replica takes over at once), let the outbox finish the entries in flight, wait for the last PUBACKs, then DISCONNECT. A signal that arrives while a handler runs starts the stop once the handler returns; a second signal while stopping ends the process at once with exit code 1. The exit code of a graceful stop is 0, so the process manager starts a fresh process. A failure the runtime cannot recover from (credentials refused, a subscription refused, the client id taken over) exits with 1.
- **Restart for a deployment.** `php artisan harken:restart` sets a flag in the cache; every runtime started before it stops within `harken.restart_check_interval` seconds.
- **Health.** Every runtime writes a heartbeat (connection state and the last CONNACK's `session_present`, leader state, outbox, counters, memory, loop lag) to the cache every `harken.health.interval` seconds; `harken:status --json` prints it. `harken:status` checks the runtimes on its own host: it fails when none runs, when a heartbeat is older than `harken.health.stale_after` seconds, or when the broker connection has been down that long. A stale heartbeat of a process that a newer one on the same host replaced is ignored. Use a cache store that every process can read (Redis, database, file on a shared disk).
- **Logs.** Life cycle, connection, election and outbox events go to the `harken.logging.channel` log channel; credentials and payloads are never logged.
- **HTTP endpoint.** With `HARKEN_HTTP=true` (or the `prometheus` metrics driver) every runtime process answers `GET /health` on `harken.http.host`:`harken.http.port` (default `0.0.0.0:9180`): 200 with its status as JSON while healthy, 503 otherwise. It suits probes that speak HTTP, such as Kubernetes; `harken:status` remains the command for a container health check.

## Events

The runtime dispatches Laravel events. Listeners run on the event loop, inside the runtime process: keep them short, or make them queued (`ShouldQueue`). An exception in a listener is reported and does not stop the runtime.

| Event | When | Properties |
|---|---|---|
| `Whatef\Harken\Events\ConnectionStateChanged` | a broker connection changes state (`disconnected`, `connecting`, `connected`, `reconnecting`, `stopping`) | `connection` (`main` or `leader`), `clientId`, `from`, `to` (`ConnectionState`), `error` (why it left the connected state, or null), `sessionPresent` (on `connected` only: whether the broker resumed the session it kept for the client id; false on the leader connection after a takeover or reconnect means the broker discarded the session and the QoS 1 messages it held, so catch up by other means) |
| `Whatef\Harken\Events\MessageHandlerFailed` | an inline handler threw, or a `->queued()` job could not be pushed | `route`, `message`, `exception`, `failedJobId` |
| `Whatef\Harken\Events\NotificationHandlerFailed` | the same for a NOTIFY handler | `route`, `notification`, `exception`, `failedJobId` |

A failed message is still acknowledged, so it is not delivered again. With `HARKEN_FAILED_JOBS=true` (`harken.handlers.failed_jobs`) it is also stored in the queue's failed job store as the job that runs the same handler, and `failedJobId` names the entry: `php artisan queue:retry <id>` then runs the handler again on a queue worker, with the same message bytes. This works for class handlers (closures cannot be stored) and also catches `->queued()` jobs that could not be pushed because the queue was unreachable.

## Metrics

`HARKEN_METRICS_DRIVER` picks where metrics go:

| Driver | What it does |
|---|---|
| `null` (default) | nothing |
| `prometheus` | every runtime process serves `GET /metrics` in the Prometheus text format on the HTTP endpoint; scrape each replica |
| `log` | one log line every `harken.metrics.log_interval` seconds (60) with counter increases, gauges, and for each histogram over the interval its count, average, p50 and p99 (estimated from the buckets, as `histogram_quantile()` does) and maximum, in seconds, such as `handler_seconds{sensors/{id}/report}.p99` |
| `pulse` | records to [Laravel Pulse](https://laravel.com/docs/pulse) every `harken.metrics.pulse_interval` seconds (15), for the Harken cards on the Pulse dashboard; needs `laravel/pulse` |

Metrics are kept per process in memory. Work done outside the runtime (queue workers running `->queued()` handlers, web requests queuing publishes) is not counted here.

| Metric | Type | Labels |
|---|---|---|
| `harken_messages_received_total` | counter | `connection` (`main`, `leader`) |
| `harken_messages_published_total` | counter | `connection` |
| `harken_messages_handled_total` | counter | `route` |
| `harken_messages_unmatched_total` | counter | |
| `harken_notifications_handled_total` | counter | `channel` |
| `harken_handler_seconds` | histogram | `route` or `channel` |
| `harken_handlers_slow_total`, `harken_handlers_failed_total` | counter | `route` or `channel` |
| `harken_connected` | gauge | `connection` |
| `harken_reconnects_total` | counter | `connection` |
| `harken_leader` | gauge | |
| `harken_leader_takeovers_total` | counter | |
| `harken_leader_takeover_seconds`, `harken_leader_gap_seconds` | histogram | |
| `harken_leader_last_takeover_seconds`, `harken_leader_last_gap_seconds` | gauge | |
| `harken_outbox_published_total`, `harken_outbox_dropped_total`, `harken_outbox_expired_total`, `harken_outbox_trimmed_total` | counter | `stream` |
| `harken_outbox_latency_seconds` | histogram | `stream` |
| `harken_outbox_waiting` | gauge | `stream` |
| `harken_memory_bytes`, `harken_loop_lag_seconds`, `harken_start_time_seconds` | gauge | |

A ready-made Grafana dashboard is in [`docker/monitoring/grafana/dashboards/harken.json`](../docker/monitoring/grafana/dashboards/harken.json): import it into Grafana with a Prometheus data source, or start Prometheus and Grafana with it already provisioned through `docker compose -f docker/monitoring/compose.yml up -d` (Grafana on port 3000). It shows the leader and replicas, traffic, handlers, the outbox, takeovers, the processes and, when EMQX is scraped too, its dropped deliveries.

A Prometheus scrape job and alerts worth having (the broker exports Prometheus metrics too: EMQX serves them at `/api/v5/prometheus/stats` on its dashboard port, and its `emqx_delivery_dropped_queue_full` belongs on the same dashboard):

```yaml
scrape_configs:
  - job_name: harken
    static_configs:
      - targets: ["harken-1:9180", "harken-2:9180"]
```

```text
sum(harken_leader) == 0                                   # no replica receives the leader routes
sum(harken_outbox_waiting) > 1000                         # the outbox is falling behind
increase(harken_reconnects_total[10m]) > 5                # the broker connection is unstable
increase(harken_handlers_failed_total[5m]) > 0            # handlers are failing
histogram_quantile(0.99, sum by (le) (rate(harken_outbox_latency_seconds_bucket[5m]))) > 0.05
```

### Laravel Pulse

With [Laravel Pulse](https://laravel.com/docs/pulse) installed and `HARKEN_METRICS_DRIVER=pulse`, every runtime process records to Pulse every 15 seconds (`HARKEN_METRICS_PULSE_INTERVAL`), so the replicas show up on the application's own `/pulse` dashboard: no Prometheus or Grafana to run. Add the cards to the published dashboard view (`php artisan vendor:publish --tag=pulse-dashboard`, then `resources/views/vendor/pulse/dashboard.blade.php`):

```blade
<x-pulse>
    <livewire:harken.replicas cols="full" />
    <livewire:harken.messages cols="6" />
    <livewire:harken.handlers cols="6" />

    <livewire:pulse.servers cols="full" />
    {{-- the other Pulse cards --}}
</x-pulse>
```

| Card | Shows |
|---|---|
| `harken.replicas` | every `harken:start` process: leader or standby, broker connection state, messages received per second, memory and outbox backlog, as last reported. A replica silent for two intervals shows as gone; one that stopped or went silent more than `ignore-after` ago (10 minutes; `ignore-after="1 hour"`) is left out. |
| `harken.messages` | messages received and published per broker connection over the period, per second on average and at the busiest point of the graph, with handler failures, outbox drops, reconnects and leader takeovers |
| `harken.handlers` | per route and NOTIFY channel: messages handled, failed and slow, and the average handler time; sortable |

Each process writes a few aggregated rows per interval, never one per message, so Pulse's storage does not grow with MQTT traffic. The write runs on the event loop: with Pulse's default `storage` ingest it is one database transaction every interval (point `PULSE_INGEST_DRIVER=redis` at Redis to keep it shorter). Pulse drops what it cannot write and hands the error to `Pulse::handleExceptionsUsing()` (silently by default).

## Planned

- Per-route acknowledgement policy for QoS 1 (`ack after handler`).
- Payload decoders (opt-in; raw bytes stay the default).
