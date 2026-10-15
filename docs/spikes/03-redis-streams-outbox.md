# Spike 03 — Redis Streams outbox on the event loop

**Question.** Can a runtime consume a Redis Streams consumer group with `XREADGROUP BLOCK` on a dedicated async connection (`clue/redis-react`) without stalling the rest of the loop? Does "publish → PUBACK → XACK" plus `XAUTOCLAIM` on restart give at-least-once delivery? Is the publish latency target met while the same process receives 10 000 msg/s (B1: p99 ≤ 50 ms)?

**Verdict: pass.**
- On Linux with EMQX, outbox publish latency was **p99 5.1 ms (stream_select) / 6.4 ms (ext-uv) while receiving 10 000 QoS 1 msg/s** with no inbound loss.
- XAUTOCLAIM recovers entries left by a crashed consumer.
- A blocking read needs its own connection.

| | |
|---|---|
| Script | [`spikes/03-redis-outbox.php`](../../spikes/03-redis-outbox.php) (E6 runs when `SPIKE_MQTT_HOST` is set) |
| Linux run | test VM (Ubuntu 24.04, 2 vCPU, 4 GB) shared by EMQX 5.8.9, Redis 7.4.11, PostgreSQL, the load generator and the runtime process. PHP 8.5.11, `StreamSelectLoop` and `ExtUvLoop` |
| Windows run | Windows 11, PHP 8.5.11, Redis 7.2.4 on localhost, `StreamSelectLoop` (no broker) |
| Publish stand-in | E3–E5 replace the broker with a 2 ms timer; E6 uses a real EMQX connection |

## Results

| # | Experiment | Linux, stream_select | Linux, ext-uv | Windows |
|---|---|---|---|---|
| E1 | `PING` while `XREADGROUP BLOCK 1500` is pending, same / second connection | 1612 ms / **0.6 ms** | 1547 ms / 0.4 ms | 1523 ms / 0.6 ms |
| E2 | 1 ms timer lag p99, idle loop → with pending BLOCK reads | 0.22 → 0.31 ms | 1.1 → 1.1 ms | 15.3 → 15.6 ms (timer floor) |
| E3 | 1 000 entries/s: XADD p99; enqueue → XACK p50 / p99 | 0.35 ms; 2.2 / 6.4 ms | 0.38 ms; 2.2 / 2.9 ms | 0.54 ms; 15.5 / 19.1 ms |
| E3 | 5 000 entries/s: XADD p99; enqueue → XACK p50 / p99 | 0.54 ms; 2.2 / 3.9 ms | 0.27 ms; 1.7 / 2.5 ms | 0.37 ms; 10.0 / 21.8 ms |
| E4 | urgent entries behind a 20 000-entry normal backlog: urgent enqueue → XACK p50 / p99 | 2.7 / 12.9 ms | 2.7 / 13.4 ms | 16.4 / 39.0 ms |
| E5 | consumer reads 50, "crashes" before XACK; restarted consumer runs `XAUTOCLAIM` | 50 reclaimed, XPENDING 0 | same | same |
| E6 | **outbox → EMQX QoS 1 → PUBACK → XACK**, urgent 100/s for 5 s, idle | p50 0.66 / p99 1.6 ms | p50 0.62 / p99 1.8 ms | — |
| E6 | same, **while receiving 10 000 QoS 1 msg/s** (inline HMAC per message) | **p50 2.9 / p99 5.1 ms**, inbound 60 000 / 60 000 | **p50 4.1 / p99 6.4 ms**, inbound 60 000 / 60 000 | — |

## Findings

1. **A blocking read needs its own connection (E1).** Redis serves one connection's commands in order, and `clue/redis-react` pipelines on one socket. A command sent on the reading connection waits for the whole BLOCK timeout. The outbox uses **two** async connections: one only for `XREADGROUP BLOCK`, one for `XACK` / `XAUTOCLAIM` / `XPENDING`. Neither is Laravel's synchronous Redis connection.
2. **The loop is not blocked (E2).** A pending BLOCK read leaves timer lag where it is on an idle loop.
3. **Handing off a publish from outside the runtime is fast (P3).** `XADD` through phpredis has p99 ≤ 0.54 ms on both platforms.
4. **B1 is met with a wide margin (E6).** With the same process taking 10 000 QoS 1 msg/s, outbox publishes reach the broker and are acknowledged in p99 5–6 ms, against a 50 ms target, on a 2 vCPU VM that also hosts the broker, Redis and the load generator. Heavier inline handlers will move this number. The load test with realistic handlers (§ "Still open") is the next measurement.
5. **Priority by stream order works (E4).** `XREADGROUP … STREAMS urgent normal > >` returns both streams in one reply. Handling the urgent part first bounds urgent latency to about one batch even behind a 20 000-entry backlog. The backlog drained at ≈17 000–22 000 entries/s with the 2 ms stand-in. Against a real broker the drain rate must be capped; see spike 01 finding 6.
6. **At-least-once recovery works (E5).** Entries read but not acked stay pending, and a restarted consumer takes them over with `XAUTOCLAIM <min-idle>`. An entry that was published but not acked before a crash is published **again**; that duplicate is the at-least-once contract.
7. **ext-uv vs stream_select.** No meaningful difference at this socket count, as expected: the loop watches a handful of sockets.
8. **Windows is a development platform only.** On Windows, timers and `stream_select` timeouts resolve to ≈15.6 ms, which dominates every latency number there. Socket readiness is not affected.
9. **`react/dns` on Windows 11.** Default DNS discovery shells out to `wmic`, which no longer exists, hits a PHP 8.5 deprecation and falls back to `8.8.8.8`. `ReactSocketTransport` should skip DNS for IP literals and accept a configured nameserver.

## Design consequences for `RedisStreamsOutbox`

- Two dedicated async connections (reader, acker).
- Read loop: `XREADGROUP GROUP harken <client-id> COUNT n BLOCK block_ms STREAMS urgent normal > >`, urgent entries first.
- Each entry: publish → on PUBACK `XACK` (QoS 0 entries: `XACK` after the socket write). Entries in flight are bounded by a window, and the drain rate is capped so a backlog cannot starve inbound delivery on the shared connection.
- On start and periodically: `XAUTOCLAIM … min-idle = claim_idle_ms` to take over entries from dead replicas.
- On shutdown: stop reading, wait for in-flight PUBACK → XACK up to the grace period. Unacked entries stay pending for another replica.
- Consumer name = MQTT client id, so pending entries can be traced to a process.

## Still open

- Load test with realistic inline handlers (JSON parse + HMAC + in-memory update) and slow handlers mixed in, compared side by side with a synchronous polling loop on the same VM.
- Stream trimming (`XADD MAXLEN ~` or `XTRIM MINID`) so acked entries do not accumulate.
- What `Harken::publish()` returns outside the runtime when Redis is unreachable (currently: a rejected promise).
