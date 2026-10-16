# End-to-end checks and measurements

Runs of real `harken:start` processes against the stack in `docker/compose.yml` (EMQX 5.8.9, Redis 7.4, PostgreSQL 17) on one test VM (Ubuntu 24.04, 2 vCPU, PHP 8.5, `StreamSelectLoop`). The broker, Redis, the load generator and the processes under test share the two cores. Scripts live in [`tests/E2E`](../tests/E2E); they boot a bare Laravel application through Orchestra Testbench (`php tests/E2E/artisan harken:start`).

## Leader takeover

`php tests/E2E/takeover.php kill|term` starts two replicas with a leader route (lease 5 s, Redis lock), publishes QoS 1 messages at 100 per second for 12 s, and stops the leader 3 s in. Each replica also has a leader timer.

| Protocol | Leader stopped by | Runs | Lost | Received twice | Both receiving at once | Takeover after the stop |
|---|---|---|---|---|---|---|
| MQTT 5 | SIGKILL | 3 | 0 | 0 | never | 5.14 s |
| MQTT 5 | SIGTERM | 3 | 0 | 0 | never | 0.12 – 0.13 s |
| MQTT 3.1.1 | SIGKILL | 2 | 0 | 0 | never | 5.15 s |
| MQTT 3.1.1 | SIGTERM | 2 | 0 | 0 | never | 0.14 s |

- After a crash the standby takes over when the lease runs out; the broker keeps queuing QoS 1 messages in the leader session meanwhile, and the new leader receives them on connect.
- A graceful stop releases the lock at once, so the standby takes over within its 0.25 s retry interval.
- A leader timer (`Harken::every(0.2, …)`, leader by default) ran on the first leader until it stopped and on the new leader from the takeover on, never on both at once.
- The queue of a disconnected session is bounded by the broker (EMQX `mqtt.max_mqueue_len`, 1000 by default). At 100 messages per second a 5 s takeover queues about 500; size the queue for the inbound rate times the takeover time.

## Life cycle

`php tests/E2E/lifecycle.php`: `harken:status` is healthy while a runtime runs and fails once none does; `Harken::publish()` from another process goes through the outbox to the broker; `harken:restart` stops the runtime gracefully (exit code 0) within the restart check interval; `--max-time` stops it by itself.

## Latency isolation under load

`php tests/E2E/load.php inline|queued|sync <rate>` follows the scenario of the requirements (§5.3):

- Devices send QoS 1 reports of about 400 bytes at 1 000, 3 000 and 10 000 messages per second over 1 000 topics, for 10 s. The handler parses the JSON, checks an HMAC-SHA256 signature and updates per-device state in memory.
- Five reports per second go to a handler that blocks for 50 ms (standing in for database round trips or an external call).
- Meanwhile the application queues 20 commands per second in the outbox stream, and a subscriber measures each command from `XADD` to delivery. This is the publish latency that matters for a command such as "open the door".

Targets:
- **inline**: `harken:start`, the slow handler runs on the event loop;
- **queued**: `harken:start`, the slow handler is `->queued()` and a `queue:work` process runs it;
- **sync**: a synchronous loop doing the same work (`tests/E2E/Load/sync-loop.php`): it waits up to 2 ms for traffic, runs every handler inline, and between messages takes commands from the same stream and publishes each one QoS 1, waiting for its PUBACK. It models a loop built on a synchronous MQTT client; it is not that client.

| Inbound / s | Target | Inbound lost | Command latency p50 | p99 | max | CPU of the process |
|---|---|---|---|---|---|---|
| 1 000 | sync | 0 / 10 050 | 7.62 ms | 37.58 ms | 39.31 ms | 3.7 % |
| 1 000 | inline | 0 / 10 050 | 0.83 ms | 30.23 ms | 33.92 ms | 3.7 % |
| 1 000 | queued | 0 / 10 050 | 0.71 ms | **2.58 ms** | 2.68 ms | 4.4 % |
| 3 000 | sync | 0 / 30 050 | 6.19 ms | 39.57 ms | 41.41 ms | 6.3 % |
| 3 000 | inline | 0 / 30 050 | 0.84 ms | 33.74 ms | 34.90 ms | 7.5 % |
| 3 000 | queued | 0 / 30 050 | 0.71 ms | **2.81 ms** | 4.42 ms | 8.1 % |
| 10 000 | sync | 0 / 100 050 | 2.33 ms | 48.39 ms | 51.78 ms | 16.0 % |
| 10 000 | inline | 0 / 100 050 | 1.41 ms | 35.15 ms | 43.89 ms | 20.2 % |
| 10 000 | queued | 0 / 100 050 | 1.17 ms | **3.29 ms** | 4.16 ms | 22.9 % |

No command was lost in any run. CPU is one core's worth (100 % = one core) over the 10 s of load.

- **Queued slow handlers keep publishing fast.** With the 50 ms handler on a queue worker, the command latency p99 stays at 2.6 – 3.3 ms up to 10 000 inbound messages per second: the event loop never waits for it.
- **Inline slow handlers delay everything behind them**, in Harken as in a synchronous loop: a command that arrives while a 50 ms handler runs waits for it, which sets the p99 at 30 – 35 ms. Harken still answers faster at the median (0.8 – 1.4 ms against 2.3 – 7.6 ms), because it does not poll and publishes without waiting for each PUBACK.
- **No inbound message was lost** at any rate in any target: every process handled every message the generator sent. The broker's `delivery.dropped.queue_full` counter did not move in the last five runs. It did move during the first four, but for two leader sessions that earlier trial runs had left subscribed to the same topics, not for the processes measured; `load.php` now ends its leader session when it is done.
- **CPU.** At 1 000 messages per second both use the same; at 3 000 and 10 000 Harken uses about 20 – 25 % more than the synchronous loop: it resolves each handler from the container and routes each message, where the loop calls one handler directly. Both stay around a fifth of a core at 10 000 messages per second.
