# Spikes

Throwaway experiments that settle design questions before the runtime is built. Scripts live in [`/spikes`](../../spikes); they are not part of the package. Linux runs use `docker/compose.yml` (EMQX 5.8.9, Redis, PostgreSQL) and the PHP image in `docker/php`.

| # | Topic | Verdict | Headline |
|---|---|---|---|
| [01](01-connect-qos1-tls.md) | binsoul/net-mqtt + react/socket against EMQX: QoS 1, TLS, keepalive | **pass** | PUBACK RTT p99 0.21 ms; 20 000 msg/s inbound without loss; EMQX drops QoS 1 when a session queue overflows |
| [02](02-shared-subscription.md) | `$share` from MQTT 3.1.1 clients on EMQX 5 | **pass** | one member per message; a crashed member's unacked messages are redispatched |
| [03](03-redis-streams-outbox.md) | Redis Streams outbox on the loop | **pass** | outbox publish p99 5–6 ms while receiving 10 000 msg/s (target 50 ms) |
| [04](04-pg-listen-notify.md) | PostgreSQL LISTEN/NOTIFY on the loop | **pass** | NOTIFY → handler p99 ≈ 1 ms; dead sockets must leave the loop at once |

Cross-cutting findings:

- **EMQX session queues drop silently.** QoS 1 deliveries beyond `max_inflight` + `max_mqueue_len` (32 + 1000 by default) are dropped and only counted in `delivery.dropped.queue_full`. The outbox must drain at a bounded rate, and deployments should size the queue and alert on that metric.
- **Dead streams kill the loop.** A stream that fails inside its read callback must be removed from the loop in that callback; `StreamSelectLoop` throws on an unselectable stream and stops everything.
- **Windows timer floor.** Timers and `stream_select` timeouts on Windows resolve to about 15.6 ms; socket readiness does not. Windows is fine for development, but latency is measured on Linux.
- **DNS on Windows 11.** `react/dns` system config discovery shells out to `wmic`, which is gone, and falls back to `8.8.8.8`. The transport should skip DNS for IP literals and allow a configured nameserver.
- **ext-uv** made no measurable difference at this socket count; it stays an optional, automatically used backend.

## Design questions

### 1. MQTT 5 in the first release?

**No.** EMQX 5 serves shared subscriptions to MQTT 3.1.1 clients (spike 02), which was the main reason to pull MQTT 5 in early. The codec sits behind `Codec`, packets are Harken's own value objects and `Message::$properties` already exists, so an MQTT 5 codec can be added later without API changes. Latency metrics can use the enqueue timestamp the outbox entries already carry.

### 2. Default acknowledgement policy for inbound QoS 1

**Acknowledge when the inline handler has finished, or when a `->queued()` job has been pushed, whether the handler succeeded or failed.** A per-route option allows acknowledging on receipt.

- Inline handlers run to completion before the next message anyway, so sending the PUBACK after the handler adds no measurable latency.
- A failed handler is still acknowledged. Unacknowledged messages occupy the broker's in-flight window (32) and then the session queue, and a full queue makes EMQX drop messages (spike 01). Failures go to logs and metrics, and optionally to the failed-job store.
- What this buys is protection against a **crash during the handler**:
  - with **shared subscriptions**, EMQX redispatches the crashed member's unacknowledged messages to the other members, with clean sessions and random client ids (spike 02);
  - with **persistent sessions** (`clean_session = 0` and a stable client id), EMQX redelivers after reconnect with DUP = 1 (spike 01);
  - with a plain subscription and a clean session there is no redelivery, which the docs must say plainly.
- Both redelivery paths are at least once, so handlers should be idempotent.

### 3. Default driver for publishing from outside the runtime

**Redis Streams outbox.** The hand-off from a web request is an `XADD` (p99 ≤ 0.54 ms). Delivery is at least once through a consumer group, `XACK` after PUBACK and `XAUTOCLAIM` on restart. Latency stays low under inbound load (spike 03). A direct short-lived broker connection remains a possible secondary driver for apps that run no runtime.

### 4. Keepalive vs. slow inline handlers

**Ping at keepalive / 2 and keep the slow-handler warning; no separate I/O path.**
- EMQX drops a silent client after exactly 1.5 × keepalive (spike 01). With the default 60 s, a single inline handler would have to block the loop for about a minute to lose the session, far beyond the 100 ms slow-handler threshold that already reports it.
- The more realistic failure under load is the broker's session queue overflowing while the loop is busy, which drops messages without disconnecting. That is handled by pacing the outbox, sizing `max_mqueue_len`, and alerting on `delivery.dropped.queue_full`, not by keepalive.
- A loop stuck for good must be caught by the health check so the process is restarted and its shared-subscription messages are redispatched.
