# Spike 04 — PostgreSQL LISTEN/NOTIFY as an event-loop source

**Question.** Can the connection socket from ext-pgsql's `pg_socket()` be registered with `Loop::addReadStream()` so that `NOTIFY`s reach the runtime with no extra process? If not, what does polling cost?

**Verdict: pass.** `pg_socket()` + `addReadStream()` delivers notifications with sub-millisecond latency and no idle cost. Polling through `Pdo\Pgsql::getNotify()` is a workable fallback for hosts without ext-pgsql.

| | |
|---|---|
| Script | [`spikes/04-pg-notify.php`](../../spikes/04-pg-notify.php) |
| Environment | Windows 11, PHP 8.5.11 NTS (ext-pgsql, pdo_pgsql), `StreamSelectLoop`, PostgreSQL 18.0 on localhost |
| Status | Windows run done. Linux re-run (stream_select / ext-uv) pending (test VM) |

## Results (Windows)

| # | Experiment | Result |
|---|---|---|
| E1 | 100 NOTIFY/s for 3 s from another process | 300/300 received; NOTIFY → callback **p50 0.33 ms, p99 1.03 ms** |
| E2 | 10 000 NOTIFYs committed in one transaction (unique payloads) | 10 000/10 000 received in 42 readable callbacks; delivery finished ≈40 ms after the 39 ms sending query |
| E3 | idle 5 s | no measurable CPU |
| E3 | backend killed with `pg_terminate_backend()` | 2 readable callbacks, `pg_consume_input()` returns false, status BAD. After removing the stream and reconnecting + `LISTEN`: 100/100 received |
| E4 | fallback: `Pdo\Pgsql::getNotify(…, 0)` polled every 10 / 50 / 100 ms | 300/300 at every interval. Latency p50 15.5 / 32 / 62 ms, p99 16.6 / 64 / 110 ms (10 ms is limited by the 15.6 ms Windows timer floor; see spike 03) |

## Findings

1. **`pg_socket()` returns a selectable stream**, and `StreamSelectLoop` wakes on it right away even on Windows. Socket readiness is not subject to the timer floor, which is why E1 latency is sub-millisecond while timers are ≈15 ms.
2. **Drain fully on each wake-up:** `pg_consume_input()`, then `pg_get_notify()` until it returns false. A 10 000-notification burst arrives over several readable events; nothing stays stuck in libpq's buffer.
3. **A dead connection must leave the loop right away.** In the first run the callback logged the failure and returned. On the next turn `stream_select()` warned "Cannot represent a stream of type PostgreSQL link as a select()able descriptor" and threw `ValueError: No stream arrays were passed`, which **kills the whole loop**: MQTT included. The source must call `removeReadStream()` inside the failing callback, then reconnect with backoff and re-`LISTEN`.
4. **NOTIFY is a hint, not a queue.**
   - PostgreSQL folds identical `(channel, payload)` pairs sent in one transaction into one notification. The first burst run "lost" 6 609 of 10 000 that way: per-row timestamps repeated within the same microsecond.
   - Notifications sent while the listener is disconnected are gone.
   - Applications should therefore treat a notification as "something changed, go look", and the source should tell handlers when it has reconnected so they can sweep for missed work. This matches the intended use: a wake-up for a sweep that a periodic timer (L2) also runs.
5. **ext-pgsql is a separate extension from pdo_pgsql.** Many Laravel images ship only pdo_pgsql. Without ext-pgsql, PHP 8.4's `Pdo\Pgsql::getNotify($fetchMode, 0)` on a periodic timer works. The cost is latency ≈ interval and a wake-up per tick. It cannot share the event-driven path because PDO exposes no socket.

## Design consequences for `PgNotifySource`

- Driver selection: `pg_socket()` path when `ext-pgsql` is loaded; otherwise `Pdo\Pgsql` polling with a configurable interval (default 100 ms) and a startup log line saying which one is active.
- Use a dedicated connection that is never shared with Eloquent, and `LISTEN` on every configured channel after each (re)connect.
- On read failure: `removeReadStream()` → state `reconnecting` → backoff (same `ExponentialBackoff`) → reconnect → `LISTEN` → emit a "resync" event to handlers.
- Handler signature: `(string $channel, string $payload)`, run inline with the same isolation and slow-handler reporting as MQTT handlers.
- Registration API (planned, see docs/api.md) to be proposed separately.

## Still open

- Linux re-run with stream_select and ext-uv; idle CPU of the polling fallback measured with Linux-resolution timers.
- Detecting a silently dead TCP connection (no FIN/RST): TCP keepalive (`keepalives_idle` in the DSN) vs. a periodic `SELECT 1` on the listening connection.
