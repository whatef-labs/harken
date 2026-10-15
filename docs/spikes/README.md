# Spikes

Throwaway experiments that settle design questions before the runtime is built. Scripts live in [`/spikes`](../../spikes); they are not part of the package.

| # | Topic | Status | Verdict |
|---|---|---|---|
| [01](01-connect-qos1-tls.md) | binsoul/net-mqtt + react/socket: connect, QoS 1, TLS | local smoke run only | pending EMQX run |
| [02](02-shared-subscription.md) | `$share` from MQTT 3.1.1 clients on EMQX 5 | local smoke run only | pending EMQX run |
| [03](03-redis-streams-outbox.md) | Redis Streams outbox on the loop | Windows run | **pass** (latency target needs Linux + broker) |
| [04](04-pg-listen-notify.md) | PostgreSQL LISTEN/NOTIFY on the loop | Windows run | **pass** |

Cross-cutting findings so far:

- **Windows timer floor.** Timers and `stream_select` timeouts on Windows resolve to about 15.6 ms; socket readiness does not. Latency targets are measured on Linux.
- **Dead streams kill the loop.** A stream that fails inside its read callback must be removed from the loop in that callback. `StreamSelectLoop` throws on an unselectable stream and stops everything.
- **DNS on Windows 11.** `react/dns` system config discovery shells out to `wmic`, which is gone, and falls back to `8.8.8.8`. The transport should skip DNS for IP literals and allow a configured nameserver.

## Design questions

Current recommendation per question. Items marked *pending* wait for the EMQX runs of spikes 01 and 02.

### 1. MQTT 5 in the first release?

**No.** The codec sits behind `Codec`, packets are Harken's own value objects, and `Message::$properties` already exists, so an MQTT 5 codec can be added without API changes. The two features that would pull it in early are:
- shared subscriptions: *pending* spike 02 E1 on 3.1.1;
- per-message metadata for latency metrics: outbox entries already carry an enqueue timestamp, and inbound latency can come from the payload.

### 2. Default acknowledgement policy for inbound QoS 1

**Proposed: acknowledge when the inline handler has finished, or when a `->queued()` job has been pushed, whether the handler succeeded or failed.** A per-route override covers "acknowledge on receipt".

- Inline handlers already run to completion before the next message, so sending the PUBACK after the handler instead of before it adds no measurable latency.
- A failed handler must still be acknowledged. An unacknowledged message holds one of the broker's in-flight slots (EMQX default `max_inflight` 32 for QoS 1), and enough of them stall delivery to the client.
- The policy only protects against a **crash during the handler**, and only if the broker redelivers. With per-replica random client ids and clean sessions, that redelivery comes from shared-subscription redispatch (*pending* spike 02 E2) or persistent sessions with stable ids (*pending* spike 01 E3). If neither holds, the honest default is "acknowledge on receipt" plus documentation.

### 3. Default driver for publishing from outside the runtime

**Redis Streams outbox (settled; spike 03).** The hand-off from a web request is an `XADD` (p99 ≤ 0.54 ms locally). Delivery is at least once through consumer groups, `XACK` after PUBACK and `XAUTOCLAIM` on restart. A direct short-lived broker connection remains a possible secondary driver for apps that run no runtime.

### 4. Keepalive vs. slow inline handlers

**Ping at keepalive / 2 and keep the slow-handler warning; no separate I/O thread.**
- A broker drops a client after about 1.5 × keepalive without any packet from it (*pending* spike 01 E6 for EMQX).
- With the default keepalive of 60 s, an inline handler would have to block the loop for tens of seconds before the session is at risk, far beyond the 100 ms slow-handler threshold that already reports such handlers.
- The larger risk under load is the broker's per-client queue filling while the loop is busy (EMQX `max_mqueue_len`, default 1000). The Linux load test should watch for dropped messages, not only for disconnects.
