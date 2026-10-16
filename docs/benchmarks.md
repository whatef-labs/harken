# End-to-end checks and measurements

Runs of real `harken:start` processes against the stack in `docker/compose.yml` (EMQX 5.8.9, Redis 7.4, PostgreSQL 17) on one test VM: Proxmox guest, **2 vCPU, 3.8 GB RAM**, Ubuntu 24.04, PHP 8.5 CLI with `StreamSelectLoop` (no ext-uv). The broker, Redis, the load generators and the processes under test share the two cores, so every figure below is a floor for one runtime process, not its limit. Scripts live in [`tests/E2E`](../tests/E2E); they boot a bare Laravel application through Orchestra Testbench (`php tests/E2E/artisan harken:start`).

The throughput and latency runs (inbound, outbox, isolation) connect to the EMQX container's address on the Docker bridge. Going through the published port adds Docker's userland proxy, which took 18 % of the VM's CPU at 15 000 messages per second.

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

## Broker outage

`php tests/E2E/outage.php network|broker 30`: one replica with a leader route receives QoS 1 messages at 10 per second (20 in the earlier runs marked so below), then loses the broker for 30 s.

- `network`: the replica reaches the broker through a TCP proxy, which drops every connection and turns new ones away for 30 s. The broker keeps running, and a retained message is published to it during the outage.
- `broker`: the EMQX container is stopped for 30 s and started again, losing every session; a retained message is published as soon as it is back.

| Outage | Machine | Rate / s | Retained message received after the broker is back | Lost |
|---|---|---|---|---|
| network | x86_64 VM | 10 | 19.3 s | 0 of 573, including those sent during the outage |
| network | x86_64 VM, ext-uv | 10 | 10.2 s | 0 of 480 |
| network | aarch64 | 20 | 19.0 s | 0 |
| broker restart | x86_64 VM | 20 | 25.6 s | 0 from the resubscription on |
| broker restart | aarch64 | 20 | 12.4 s | 0 from the resubscription on |

- The replica kept running throughout and reconnected on its own; the time after the broker is back is the reconnect backoff (1 s doubling to 60 s, with jitter), so it varies from run to run and stays under a minute.
- While the network is down the broker queues the QoS 1 messages in the leader's persistent session, and the replica receives all of them on reconnect, up to `mqtt.max_mqueue_len` (1 000 by default): at 20 per second one run reconnected after 26 s and the broker dropped the 127 messages beyond that, which is why the script now sends 10 per second. Size the queue for the inbound rate times the longest outage plus the reconnect backoff.
- A broker restart without durable sessions loses the session: messages published before the replica subscribed again had no subscriber. `harken.connections.*.reconnect.max_delay` shortens that window.

## Linux aarch64

The same checks on an Oracle Cloud Ampere A1 instance (Neoverse-N1, 4 cores, 24 GB, Ubuntu 24.04, Docker 29) with the image from `docker/php` built for arm64 (PHP 8.5): 437 unit and 39 integration tests pass; leader takeover loses nothing (SIGKILL 5.0 s, SIGTERM 0.24 s); the life cycle and both outages pass. With ext-uv loaded (`ExtUvLoop`) the unit tests, takeovers, life cycle and outages pass as well.

`bench.php inbound 10000 10 2` on that instance, broker and generators on the same four cores: 0 of 100 000 lost, latency p50 1.74 ms, p99 8.52 ms, 56 % of one core, 59 MB RSS.

## Inbound throughput and latency

`php tests/E2E/bench.php inbound <rate> 10 <generators>`: one runtime process with a leader route; generator processes publish QoS 1 device reports of about 400 bytes over 1 000 topics for 10 s, each stamped with the time it was published. The handler parses the JSON, checks an HMAC-SHA256 signature, updates per-device state in memory, and samples every 100th latency from the broker publish to the handler.

| Inbound / s | Generators | Lost | Latency p50 | p99 | max | Runtime CPU | Runtime RSS |
|---|---|---|---|---|---|---|---|
| 10 000 | 2 | 0 / 100 000 | 1.09 ms | 3.17 ms | 7.24 ms | 27.1 % | 55 MB |
| 15 000 | 2 | 0 / 150 000 | 1.56 ms | 26.5 ms | 33.7 ms | 34.4 % | 55 MB |
| 20 000 | 2 | 18 285 / 200 000 | 2.57 ms | 378 ms | 402 ms | 36.6 % | 56 MB |

- Up to 15 000 messages per second every message reached its handler.
- At 20 000 the VM had no idle CPU: EMQX alone used about half of it, the generators most of the rest, and the runtime about a third of one core. The broker's session queue for the runtime filled up (`max_inflight` 32 + `max_mqueue_len` 1 000 by default) and EMQX dropped the overflow, which its `delivery.dropped.queue_full` counter matched exactly. The limit here is the machine, not the runtime process, which was still below 40 % of one core.
- The runtime spends about 27 µs of CPU per message at 10 000 per second, handler included, and its memory stays flat at 55 MB RSS (`memory_get_usage(true)`: 30 MB).

## Outbox throughput

`php tests/E2E/bench.php outbox 50000`: with a runtime running, 50 000 entries are queued in the outbox stream at once (0.3 s with a pipelined client), and the run ends when the stream is empty. Each entry is published QoS 1, acknowledged by the broker, then acknowledged and deleted in Redis.

| Entries | Drained in | Published / s | Runtime CPU |
|---|---|---|---|
| 50 000 | 2.97 s | 16 825 | 71 % |
| 50 000 | 2.81 s | 17 823 | 74 % |

At most `harken.outbox.batch` (100) entries are in flight at once; `harken.outbox.max_rate` can lower the pace.

## Publishing from a request

`php tests/E2E/artisan e2e:publish-bench 10000` calls `Harken::publish()` outside the runtime 10 000 times, as a web request would: one `XADD` on the application's Redis connection (phpredis, Redis on the same machine).

| Calls | p50 | p99 | max |
|---|---|---|---|
| 10 000 | 0.071 ms | 0.108 ms | 2.2 ms |

## MQTT codec

A 400-byte QoS 1 PUBLISH, encoded and decoded 100 000 times on the VM (`MQTT 5 + properties` adds a content type and a user property):

| Codec | Encode / s | Decode / s |
|---|---|---|
| MQTT 3.1.1 (binsoul/net-mqtt) | 671 000 | 301 000 |
| MQTT 5 (Harken) | 587 000 | 474 000 |
| MQTT 5 + properties | 247 000 | 142 000 |
## Latency isolation under load

`php tests/E2E/load.php inline|queued|sync <rate>` measures what a slow handler does to publishing:

- Devices send QoS 1 reports of about 400 bytes at 1 000, 3 000 and 10 000 messages per second over 1 000 topics, for 10 s. The handler parses the JSON, checks an HMAC-SHA256 signature and updates per-device state in memory.
- Five reports per second go to a handler that blocks for 50 ms (standing in for database round trips or an external call).
- Meanwhile the application queues 20 commands per second in the outbox stream, and a subscriber measures each command from `XADD` to delivery. This is the publish latency that matters for a command such as "open the door".

Targets:
- **inline**: `harken:start`, the slow handler runs on the event loop;
- **queued**: `harken:start`, the slow handler is `->queued()` and a `queue:work` process runs it;
- **sync**: a synchronous loop doing the same work (`tests/E2E/Load/sync-loop.php`): it waits up to 2 ms for traffic, runs every handler inline, and between messages takes commands from the same stream and publishes each one QoS 1, waiting for its PUBACK. It models a loop built on a synchronous MQTT client; it is not that client.

| Inbound / s | Target | Inbound lost | Command latency p50 | p99 | max | CPU of the process |
|---|---|---|---|---|---|---|
| 1 000 | sync | 0 / 10 050 | 7.30 ms | 33.23 ms | 34.65 ms | 3.5 % |
| 1 000 | inline | 0 / 10 050 | 0.79 ms | 31.84 ms | 34.25 ms | 4.3 % |
| 1 000 | queued | 0 / 10 050 | 0.72 ms | **2.16 ms** | 5.15 ms | 5.2 % |
| 10 000 | sync | 0 / 100 050 | 2.81 ms | 50.43 ms | 50.82 ms | 15.2 % |
| 10 000 | inline | 0 / 100 050 | 1.12 ms | 34.22 ms | 34.72 ms | 20.2 % |
| 10 000 | queued | 0 / 100 050 | 1.08 ms | **3.35 ms** | 5.00 ms | 22.7 % |

An earlier series, through the published broker port, also measured 3 000 messages per second (p99: sync 39.6 ms, inline 33.7 ms, queued 2.8 ms) and found the same picture.

No command was lost in any run. CPU is one core's worth (100 % = one core) over the 10 s of load.

- **Queued slow handlers keep publishing fast.** With the 50 ms handler on a queue worker, the command latency p99 stays at 2.2 – 3.4 ms up to 10 000 inbound messages per second: the event loop never waits for it.
- **Inline slow handlers delay everything behind them**, in Harken as in a synchronous loop: a command that arrives while a 50 ms handler runs waits for it, which sets the p99 at 30 – 35 ms. Harken still answers faster at the median (0.8 – 1.1 ms against 2.8 – 7.3 ms), because it does not poll and publishes without waiting for each PUBACK.
- **No inbound message was lost** at any rate in any target: every process handled every message the generator sent.
- **CPU.** At 1 000 messages per second both use the same; at 3 000 and 10 000 Harken uses about 20 – 25 % more than the synchronous loop: it resolves each handler from the container and routes each message, where the loop calls one handler directly. Both stay around a fifth of a core at 10 000 messages per second.
