# Spike 04 — PostgreSQL LISTEN/NOTIFY as an event-loop source

**Question.** Can the connection socket from ext-pgsql's `pg_socket()` be registered with `Loop::addReadStream()` so that `NOTIFY`s reach the runtime with no extra process? If not, what does polling cost?

**Verdict: pass.** `pg_socket()` + `addReadStream()` delivers notifications with sub-millisecond median latency on Linux and Windows, on both `stream_select` and ext-uv. Polling through `Pdo\Pgsql::getNotify()` is a workable fallback without ext-pgsql.

| | |
|---|---|
| Script | [`spikes/04-pg-notify.php`](../../spikes/04-pg-notify.php) |
| Linux run | test VM (Ubuntu 24.04, 2 vCPU), PostgreSQL 17.11 (`docker/compose.yml`), PHP 8.5.11 with ext-pgsql and pdo_pgsql, `StreamSelectLoop` and `ExtUvLoop` |
| Windows run | Windows 11, PHP 8.5.11, PostgreSQL 18.0 on localhost, `StreamSelectLoop` |

## Results

| # | Experiment | Linux, stream_select | Linux, ext-uv | Windows |
|---|---|---|---|---|
| E1 | 100 NOTIFY/s for 3 s from another process: received; NOTIFY → callback p50 / p99 | 300/300; **0.26 / 1.19 ms** | 300/300; **0.22 / 0.51 ms** | 300/300; 0.33 / 1.03 ms |
| E2 | 10 000 NOTIFYs in one transaction (unique payloads): received; readable callbacks | 10 000; 1 | 10 000; 1 | 10 000; 42 |
| E3 | CPU over 5 s idle | ≈0 | 0.065 s | ≈0 |
| E3 | backend killed: detected → stream removed → reconnect + `LISTEN` → received | yes → 100/100 | yes → 100/100 | yes → 100/100 |
| E4 | fallback `Pdo\Pgsql::getNotify(…, 0)` every 10 / 50 / 100 ms: latency p99 | 10.3 / 50.3 / 95.3 ms | 10.1 / 46.3 / 96.1 ms | 16.6 / 64 / 110 ms |
| E4 | fallback idle CPU over 5 s at 10 / 50 / 100 ms | 0.018 / 0.006 / 0.003 s | ≈0.07 s each | ≈0 |

## Findings

1. **`pg_socket()` returns a selectable stream** that both loop backends watch correctly. Socket readiness wakes the loop immediately, so delivery is sub-millisecond at the median even on Windows, where timers are limited to ≈15.6 ms.
2. **Drain fully on each wake-up:** `pg_consume_input()`, then `pg_get_notify()` until it returns false. A 10 000-notification burst arrives in one wake-up on Linux and several on Windows; nothing is left behind in libpq's buffer.
3. **A dead connection must leave the loop right away.** In the first Windows run, the callback logged the failure and returned. On the next turn `stream_select()` warned "Cannot represent a stream of type PostgreSQL link as a select()able descriptor" and threw `ValueError: No stream arrays were passed`, which **kills the whole loop**, MQTT included. The source must call `removeReadStream()` inside the failing callback, then reconnect with backoff and `LISTEN` again. Done that way, it works on every backend tested.
4. **NOTIFY is a hint, not a queue.**
   - PostgreSQL folds identical `(channel, payload)` pairs sent in one transaction into a single notification. A first burst run "lost" 6 609 of 10 000 because per-row timestamps repeated within the same microsecond.
   - Notifications sent while the listener is disconnected are gone.
   - Treat a notification as "something changed, go look". The source should tell handlers when it has reconnected so they can sweep for missed work, the same sweep a periodic timer (L2) runs anyway.
5. **ext-pgsql is a separate extension from pdo_pgsql.** Many Laravel images ship only pdo_pgsql. Without ext-pgsql, PHP 8.4+'s `Pdo\Pgsql::getNotify($fetchMode, 0)` on a periodic timer works. Latency is about the interval, and the idle cost is small.
6. **ext-uv idles slightly above zero** (≈13 ms CPU per second in this setup, independent of the polling interval) where `stream_select` measures ≈0. Not investigated further; it does not affect the choice, since ext-uv stays optional.

## Design consequences for `PgNotifySource`

- Driver selection: the `pg_socket()` path when ext-pgsql is loaded; otherwise `Pdo\Pgsql` polling every `harken.sources.pgsql.poll_interval_ms` (default **10 ms**, ≈0.4 % of a core idle on Linux per E4), with a startup log line naming the active driver. The runtime never waits on a fixed 100 ms tick by default; this fallback is the only fixed interval in it.
- A dedicated connection, never shared with Eloquent, that `LISTEN`s on every configured channel after each (re)connect.
- On read failure: `removeReadStream()` → reconnect with the same `ExponentialBackoff` → `LISTEN` → emit a "resync" event to handlers.
- Handlers receive `(string $channel, string $payload)` and run inline with the same isolation and slow-handler reporting as MQTT handlers.
- Registration API to be proposed separately (planned in docs/api.md).

## Still open

- Detecting a silently dead TCP connection (no FIN/RST): TCP keepalive via the DSN (`keepalives_idle` …) vs. a periodic `SELECT 1` on the listening connection.
