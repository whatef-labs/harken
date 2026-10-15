# Spike 03 — Redis Streams outbox on the event loop

**Question.** Can a runtime consume a Redis Streams consumer group with `XREADGROUP BLOCK` on a dedicated async connection (`clue/redis-react`) without stalling the rest of the loop? Does "publish → PUBACK → XACK" plus `XAUTOCLAIM` on restart give at-least-once delivery?

**Verdict: pass on the design questions. The latency target (B1) needs a Linux re-run with a real broker.**

| | |
|---|---|
| Script | [`spikes/03-redis-outbox.php`](../../spikes/03-redis-outbox.php) |
| Environment | Windows 11, PHP 8.5.11 NTS, `StreamSelectLoop`, Redis 7.2.4 on localhost, `clue/redis-react` 2.8.0, producer using phpredis in a separate process |
| Broker | none: publish is simulated by a 2 ms timer standing in for the PUBLISH → PUBACK round trip |
| Status | Windows run done. Linux + EMQX re-run pending (test VM) |

## Results (Windows)

| # | Experiment | Result |
|---|---|---|
| E1 | `PING` while `XREADGROUP BLOCK 1500` is pending | same connection: **1523 ms**; second connection: **0.6 ms** |
| E2 | lag of a 1 ms periodic timer | idle loop p50 14.5 ms; with a BLOCK read pending p50 14.5 ms (no difference; see "Windows timer floor") |
| E3 | 1 000 entries/s for 3 s | XADD p50 0.04 ms / p99 0.54 ms; enqueue → XACK p50 15.5 ms / p99 19.1 ms; 3 000/3 000 acked, XPENDING 0 |
| E3 | 5 000 entries/s for 3 s | XADD p50 0.03 ms / p99 0.37 ms; enqueue → XACK p50 10.0 ms / p99 21.8 ms; 15 000/15 000 acked, XPENDING 0 |
| E4 | 20 000-entry normal backlog, urgent entries at 20/s | urgent enqueue → XACK p50 16.4 ms / p99 39.0 ms; the backlog drained in ≈0.9 s (≈22 000 entries/s) |
| E5 | consumer reads 50 entries, "crashes" before XACK, a new consumer runs `XAUTOCLAIM` | 50 pending → 50 reclaimed, published and acked → XPENDING 0 |

## Findings

1. **A blocking read needs its own connection (E1).** Redis serves one connection's commands in order, and `clue/redis-react` pipelines on one socket. A command sent on the reading connection waits for the whole BLOCK timeout. The outbox therefore uses **two** async connections: one only for `XREADGROUP BLOCK`, one for `XACK` / `XAUTOCLAIM` / `XPENDING`. Neither is shared with Laravel's synchronous Redis connection.
2. **The loop is not blocked (E2).** A pending BLOCK read costs nothing on the loop: timer lag is the same as on an idle loop.
3. **The out-of-runtime publish meets the "≤ 1 ms to hand off" target (P3).** `XADD` through phpredis: p99 ≤ 0.54 ms.
4. **Priority by stream order works (E4).** `XREADGROUP … STREAMS urgent normal > >` returns both streams in one reply. Handling the urgent part first bounds urgent latency to about one batch (COUNT 200) of work, even behind a 20 000-entry backlog.
5. **At-least-once recovery works (E5).** Entries read but not acked stay in the PEL. A restarted consumer reclaims them with `XAUTOCLAIM <min-idle>` and finishes them. An entry that was published but not acked before the crash is published **again**. That duplicate is the at-least-once contract, so consumers of these topics must be idempotent.
6. **Windows timer floor.** On this machine `usleep(1000)` and `stream_select(…, 1000 µs)` both take about **15.6 ms** (OS timer resolution). Every timer shorter than that fires late, so the Windows latency numbers above are dominated by this floor, not by Redis or the loop. Windows stays a development platform. **The B1 target (p99 ≤ 50 ms under 10 000 msg/s inbound) must be measured on Linux.**
7. **`react/dns` on Windows 11.** Creating a `Connector` with default DNS settings runs `wmic`, which no longer exists. The code then hits a PHP 8.5 deprecation (`preg_match_all(null)`) and falls back to `8.8.8.8` as the nameserver. LAN-only hostnames will not resolve on Windows unless a nameserver is configured. Action: `ReactSocketTransport` should skip DNS for IP literals and accept a configurable nameserver.

## Design consequences for `RedisStreamsOutbox`

- Two dedicated async connections (reader, acker), created from the outbox's Redis connection settings.
- Read loop: `XREADGROUP GROUP harken <client-id> COUNT n BLOCK block_ms STREAMS urgent normal > >`, urgent entries first.
- Each entry: publish → on PUBACK `XACK` (QoS 0 entries: XACK after the socket write). Several entries may be in flight at once, bounded by an in-flight window.
- On start and periodically: `XAUTOCLAIM … min-idle = claim_idle_ms` to take over entries from dead replicas.
- On shutdown: stop reading, wait for in-flight PUBACK → XACK up to the grace period. Unacked entries stay pending for the next replica.
- Consumer name = MQTT client id, so a stuck consumer can be traced to a process.

## Still open

- Linux + EMQX re-run: real PUBACK round trip, 10 000 msg/s inbound at the same time (B1), stream_select vs ext-uv.
- Stream trimming policy (`XADD MAXLEN ~` or `XTRIM MINID`) so acked entries do not accumulate.
- What `Harken::publish()` returns outside the runtime when Redis is down (currently: a rejected promise, to be confirmed).
