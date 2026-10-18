# End-to-end checks and measurements

Runs of real `harken:start` processes against the stack in `docker/compose.yml` (EMQX 5.8.9, Redis 7.4, PostgreSQL 17) on one test VM: Proxmox guest, **2 vCPU, 3.8 GB RAM**, Ubuntu 24.04, PHP 8.5 CLI with `StreamSelectLoop` (no ext-uv). The broker, Redis, the load generators and the processes under test share the two cores, so every figure below is a floor for one runtime process, not its limit. Scripts live in [`tests/E2E`](../tests/E2E); they boot a bare Laravel application through Orchestra Testbench (`php tests/E2E/artisan harken:start`).

The throughput and latency runs (inbound, outbox, isolation) connect to the EMQX container's address on the Docker bridge. Going through the published port adds Docker's userland proxy, which took 18 % of the VM's CPU at 15 000 messages per second.

[Six cores](#six-cores) repeats the inbound runs on the same VM with six vCPU, the runtime on a CPU of its own, to find what one process costs and where it stops.

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

## 24 hours

`php tests/E2E/soak.php 24 1000` runs two replicas (leader and standby, Prometheus metrics, Redis leader lock) for 24 hours. They get QoS 1 device reports at 1 000 per second on a leader route, ten outbox commands and five PostgreSQL notifications per second, and a message to a failing handler every ten seconds; a leader timer runs every second. Every minute the script samples each replica's RSS (`/proc`) and PHP memory (`/health`), and at the end it compares the second hour with the last one.

| Replica | RSS after 1 min | Mean RSS, second hour | Mean RSS, last hour | Range over 24 h | PHP memory |
|---|---|---|---|---|---|
| leader | 59.13 MB | 59.16 MB | 59.18 MB | 58.26 – 59.18 MB | 32 MB throughout |
| standby | 56.76 MB | 56.76 MB | 56.76 MB | 56.59 – 56.76 MB | 32 MB throughout |

- Memory stayed flat: the leader's RSS grew by 0.02 MB from the second hour to the last, the standby's not at all, and PHP memory (`memory_get_usage(true)`) never moved.
- The generator sent 86 400 000 reports and the leader handled 86 400 000 of them, plus the 8 639 sent to the failing handler: none was lost. Both replicas published from the outbox, 860 008 entries in all, and the streams were empty with nothing pending at the end. The leader handled 430 897 notifications.
- Neither replica restarted or reconnected, the leader was elected once, and the logs hold no error besides the deliberate failures. Both stopped on SIGTERM with exit code 0.
- Over the first eight hours the leader used about 6 % of one core, and the standby 0.2 %.
- Each failed handler is logged with its trace, so the 8 639 failures wrote 47 MB to the leader's log. Rotate the log of a runtime whose handlers fail often.

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

## Six cores

The same VM resized to **6 vCPU** (host: Intel Core i5-11500, one thread per core). Each runtime process runs pinned to a CPU of its own (`taskset`: CPU 5, then 4, 3, 2 for more replicas); EMQX (five schedulers), Redis, PostgreSQL, the generators and the queue workers share the other CPUs (`docker update --cpuset-cpus`). One generator per 10 000 messages per second, at least two. EMQX runs with its defaults (`mqtt.max_inflight` 32, `mqtt.max_mqueue_len` 1 000) unless a row says otherwise.

`bench.php inbound` takes its switches from the environment (see the script): `E2E_SINGLETON=1` binds the handler as a singleton, `E2E_PHP_ARGS` passes php options to the runtime (`-d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M` for the JIT, `-d extension=uv` for ext-uv), `E2E_QOS=0`, `E2E_REPLICAS`, `E2E_WORKERS`, `E2E_RUNTIME_CPUS` and `E2E_OTHER_CPUS`. CPU per message is the CPU time (user and kernel) of the runtime processes divided by the messages they handled, backlog included.

### What a message costs

10 000 QoS 1 messages per second, three runs of each configuration. None lost a message.

| Configuration | CPU per message | Runtime CPU | Latency p99 | RSS |
|---|---|---|---|---|
| Baseline: `StreamSelectLoop`, handler resolved from the container per message, no JIT, metrics `null` | 28.1 – 28.6 µs | 28 % | 1.9 – 2.0 ms | 56 MB |
| Handler bound as a singleton | 26.0 – 27.9 µs | 26 % | 1.9 – 2.1 ms | 56 MB |
| JIT (`opcache.jit=tracing`) | 22.1 – 25.3 µs | 23 % | 1.8 – 1.9 ms | 80 MB |
| Singleton and JIT | 19.6 – 20.4 µs | 20 % | 1.8 – 2.0 ms | 80 MB |
| ext-uv (`ExtUvLoop`) | 31.0 – 36.1 µs | 32 % | 1.7 – 2.0 ms | 57 MB |
| ext-uv, singleton and JIT | 21.7 – 24.9 µs | 21 % | 1.9 ms | 81 MB |
| Baseline with `prometheus` metrics | 30.3 – 32.9 µs | 30 % | 2.1 ms | 56 MB |
| Singleton and JIT with `prometheus` metrics | 22.2 – 25.3 µs | 23 % | 2.0 ms | 80 MB |
| Baseline at QoS 0 | 17.9 – 19.6 µs | 19 % | 1.4 – 1.7 ms | 56 MB |

- The benchmark handler has no constructor dependencies, so building it per message is cheap: the singleton saves 2 µs here, more for a handler with dependencies to resolve.
- The JIT shortens the PHP part of the work; the kernel part (socket reads and writes, 4 – 5 µs per message) stays. Its buffer adds 24 MB of memory. With the handler as a singleton, a message costs 20 µs instead of 28.5.
- ext-uv gained nothing at this rate: it used more CPU than `stream_select`, for a slightly lower median latency.
- The `prometheus` driver costs 2 – 3 µs per message (a counter and a histogram).
- QoS 0 saves 9 µs per message (no PUBACK, nothing to track) and cuts the broker's CPU by about 40 %.

### Where one process stops

One runtime process, QoS 1, rate raised until messages are lost. Each cell: lost, latency p99, runtime CPU.

| Inbound / s | EMQX `max_inflight` | Baseline | Singleton and JIT |
|---|---|---|---|
| 20 000 | 32 | 0, 3.5 ms, 63 % | 0, 3.5 ms, 43 % |
| 30 000 | 32 | 0, 23.6 ms, 83 % | 0, 3.6 ms, 60 % |
| 30 000 | 128 | 0, 12.1 ms, 82 % | 0, 4.0 ms, 58 % |
| 40 000 | 32 | 25 %, 43 ms, 76 % | 19 %, 54 ms, 65 % |
| 40 000 | 128 | 5.3 %, 40 ms, 86 % | 5.5 %, 43 ms, 66 % |
| 50 000 | 128 | 58 %, 160 ms, 64 % | 40 %, 203 ms, 57 % |

At QoS 0 the singleton and JIT configuration lost nothing at 30 000, 40 000, 50 000 and 60 000 messages per second (p99 2.3 – 3.1 ms, 12.5 – 13.2 µs per message, 73 % of its CPU at 60 000).

- **Above 30 000 QoS 1 messages per second the limit was the broker's delivery to one session, not the process.** The runtime's CPU was not full (57 – 86 %), both configurations lost about as much, and every lost message was dropped by EMQX from the session's full queue (`delivery.dropped.queue_full`). EMQX sends one session at most `max_inflight` unacknowledged QoS 1 messages; raising it from 32 to 128 moved the limit from 30 000 – 32 000 to about 38 000 per second. QoS 0, which has no acknowledgements, went to 60 000.
- Up to that limit the JIT and singleton configuration kept p99 at 3.5 – 4 ms, where the baseline's grew to 12 – 24 ms at 30 000 as its CPU filled up.
- At 50 000 the five CPUs of the broker and the generators were nearly full as well.

### Shared subscription over replicas (X-04)

The route `->shared('bench')`, one to four replicas each on a CPU of its own, baseline configuration, `max_inflight` 128. The broker, Redis and the generators keep the remaining CPUs: four with two replicas, two with four.

| Replicas | Inbound / s | Lost | Messages per replica | CPU per replica | CPU per message, all replicas | Latency p99 |
|---|---|---|---|---|---|---|
| 1 | 20 000 | 0 | 200 000 | 52 % | 26.4 µs | 3.0 ms |
| 2 | 20 000 | 0 | 100 000 each | 42 – 43 % | 43.7 µs | 3.2 ms |
| 4 | 20 000 | 0 | 50 000 each | 23 – 24 % | 49.0 µs | 17.4 ms |
| 2 | 30 000 | 0 | 150 000 each | 54 – 56 % | 37.5 µs | 7.0 ms |
| 2 | 40 000 | 6 of 400 000 | 200 000 and 199 994 | 61 % | 31.3 µs | 24.0 ms |
| 2 | 50 000 | 16 % | 213 136 and 208 214 | 55 – 56 % | 26.9 µs | 69 ms |

- The broker split the messages evenly. Each replica has its own session, so two replicas took 40 000 QoS 1 messages per second that one session could not; at 50 000 the four CPUs left to the broker and the generators were full.
- Splitting the same rate costs more CPU in all: each replica gets fewer messages per read, so more wake-ups and system calls per message. Add replicas for throughput beyond one session, not to save CPU.
- With four replicas the broker had two CPUs, which shows in the p99.

### Queued handlers (X-05)

The route `->queued()`, one runtime process, and 1, 2 or 4 `queue:work redis --sleep=0.01` workers sharing CPUs with the broker, Redis and the generators. Latency is from the broker publish to the handler in the worker.

| Workers | Inbound / s | Lost | Runtime: CPU per message, CPU | Worker CPU per job | Queue empty after the last message | Latency p50, p99 |
|---|---|---|---|---|---|---|
| 1 | 1 000 | 0 | 154 µs, 15 % | 605 µs | at once | 8 ms, 22 ms |
| 1 | 2 000 | 0 | 139 µs, 27 % | 574 µs | 6.7 s | 3.9 s, 6.8 s |
| 1 | 5 000 | 0 | 113 µs, 55 % | 549 µs | 30.8 s | 16.7 s, 30.7 s |
| 2 | 1 000 | 0 | 167 µs, 16 % | 752 µs | at once | 6 ms, 12 ms |
| 2 | 2 000 | 0 | 152 µs, 30 % | 695 µs | at once | 106 ms, 180 ms |
| 2 | 5 000 | 6 % | 116 µs, 53 % | 646 µs | 12.7 s | 7.6 s, 12.8 s |
| 4 | 1 000 | 0 | 171 µs, 17 % | 994 µs | at once | 6.5 ms, 12 ms |
| 4 | 2 000 | 0 | 153 µs, 30 % | 844 µs | at once | 5 ms, 18 ms |
| 4 | 5 000 | 29 % | 126 µs, 43 % | 796 µs | 2.6 s | 1.9 s, 2.7 s |

- **Queuing a message costs the runtime 110 – 170 µs, four to six times an inline handler**: Laravel builds the job payload and pushes it to Redis, and the push waits for Redis's answer on the event loop.
- That wait limits how fast one process can queue. With two and four workers polling the same Redis on the same CPUs, the push took longer and the runtime fell behind at 5 000 per second while its CPU was half idle: the broker dropped what its session queue could not hold. With one worker the same rate went through.
- One worker ran about 1 200 jobs per second, two about 2 100 and four about 2 800 on CPUs they shared with everything else.
- Queue slow work, as in [latency isolation](#latency-isolation-under-load), where it keeps publishing fast. For light messages at high rates, handle them inline, and share the route over replicas for more.

### For more throughput

- Bind handlers that keep no per-message state as singletons, and enable the JIT for the runtime's CLI: 28.5 → 20 µs per message here.
- A QoS 1 subscription is limited by the broker's delivery to its session: raise EMQX's `mqtt.max_inflight` (32 by default), and share the route over replicas beyond that.
- Use QoS 0 for messages you can afford to lose: less CPU per message, and no limit from acknowledgements.
- Queue only slow work: queuing costs the event loop more than a light handler.

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
