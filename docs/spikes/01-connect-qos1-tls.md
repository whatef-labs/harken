# Spike 01 — binsoul/net-mqtt + react/socket: connect, QoS 1, TLS

**Question.** Do binsoul/net-mqtt packets over a react/socket connection give a working MQTT 3.1.1 client against EMQX 5 (connect → subscribe → QoS 1 publish → PUBACK, TLS on 8883), with the runtime in control of when inbound QoS 1 messages are acknowledged?

**Verdict: pass.** One broker behaviour shapes the outbox design: EMQX silently drops QoS 1 deliveries when a client's session queue overflows (finding 6).

| | |
|---|---|
| Scripts | [`spikes/01-connect-qos1-tls.php`](../../spikes/01-connect-qos1-tls.php), client: [`spikes/MiniClient.php`](../../spikes/MiniClient.php) |
| Environment | test VM: Ubuntu 24.04, 2 vCPU, 4 GB. EMQX 5.8.9 with default settings (`docker/compose.yml`). PHP 8.5.11 CLI (`docker/php/Dockerfile`), `StreamSelectLoop`, host network. Broker and client share the VM |
| Reproduce | `docker compose -f docker/compose.yml up -d`, then `SPIKE_MQTT_HOST=127.0.0.1 php spikes/01-connect-qos1-tls.php` |

## Results

| # | Experiment | Result |
|---|---|---|
| E1 | connect, subscribe QoS 1, publish to own subscription | CONNACK in 7–11 ms; SUBACK `[1]`; message back with QoS 1 and binary payload intact |
| E2 | 1 000 sequential QoS 1 publishes | PUBLISH → PUBACK **p50 0.14 ms, p99 0.21 ms** |
| E2 | 10 000 × 400 B, 100 in flight, as fast as possible | ≈30 000 msg/s, all PUBACKed. Only **4 204 / 10 000 came back** to the same connection's subscription (see finding 6) |
| E3 | 5 inbound QoS 1 left unacked, TCP dropped, reconnect with the same client id | `clean_session = 0`: session present, **5/5 redelivered with DUP = 1**. `clean_session = 1`: 0 redelivered |
| E4 | retained publish; then an empty retained publish | a new subscriber gets `v1` with the retain flag; after clearing it gets nothing |
| E5 | TLS 8883 with private CA | connects (≈46–78 ms handshake); QoS 1 RTT p99 0.35 ms |
| E5 | TLS 8883 with system CAs only | fails: `certificate verify failed` (expected) |
| E5 | mutual TLS 8884 with client certificate | connects; without a certificate: closed before CONNACK (expected) |
| E6 | keepalive 4 s, client silent | EMQX closes the connection after **6.0 s** (1.5 × keepalive) |
| E6 | keepalive 4 s, PINGREQ every 2 s | stays connected; PINGRESP p99 0.6 ms |
| E7 | another process publishes 400 B QoS 1 for 3 s; this process only receives and acks | QoS 1 and QoS 0 subscriptions: **100 % at 5 000, 10 000 and 20 000 msg/s** |
| E8 | one connection publishes at a paced rate to its own subscription, 3 s | 100 % at 5 000 and 10 000 msg/s each way; 91–99 % at 15 000 and 20 000 msg/s |

During the E2 and E8 losses, EMQX counted them as `delivery.dropped.queue_full`.

## Findings

1. **Interop is fine.** binsoul's packet classes behind Harken's `Codec`, framed by `StreamParser` and carried by react/socket, work against EMQX 5.8.9 for everything MQTT 3.1.1 needs here, including TLS and mutual TLS through `TlsOptions`.
2. **PUBACK timing is the runtime's choice and it matters.** binsoul's `IncomingPublishFlow` would hand back the PUBACK together with the message, so Harken uses the packet classes only and decides itself when to acknowledge.
3. **Redelivery of unacknowledged QoS 1 needs either a persistent session or a shared subscription.** With `clean_session = 0` and a stable client id, EMQX redelivers with DUP = 1 on reconnect. With a clean session it does not; spike 02 shows the shared-subscription route.
4. **Keepalive grace is 1.5 ×**, as specified. With the default keepalive of 60 s the broker drops a silent client after 90 s, so pinging at keepalive / 2 leaves 60 s of slack for a blocked loop.
5. **Receiving is not the bottleneck.** A single PHP process on `stream_select` takes 20 000 QoS 1 msg/s from EMQX without loss when it only receives (E7). Handler time decides real throughput.
6. **EMQX drops QoS 1 deliveries when a session queue overflows.** EMQX holds up to `max_inflight` (32) unacknowledged messages per client and queues the rest up to `max_mqueue_len` (1000). Beyond that it **drops**, QoS 1 included, and only increments `delivery.dropped.queue_full`. When the same connection also publishes heavily (an unpaced 30 000 msg/s burst in E2, ≥ 15 000 msg/s in E8), deliveries to it fall behind and get dropped. Consequences:
   - The outbox must drain at a bounded rate with a bounded in-flight window, so that working off a backlog cannot starve inbound delivery on the shared connection.
   - Operators should size `max_mqueue_len` (and possibly `max_inflight`) for reconnect storms and alert on `delivery.dropped.queue_full`. This belongs in the deployment docs.
   - One connection for subscribe and publish (C1) is fine at the target rates (lossless at 10 000 msg/s each way). A separate publishing connection stays an option should publish volume ever approach that.

## Design consequences

- `Connection` uses `PacketIdPool` plus its own in-flight map for outbound QoS 1 and resends with DUP = 1 after reconnect when the session was kept.
- Keepalive: send PINGREQ when nothing was sent for keepalive / 2; treat a missing PINGRESP within keepalive / 2 as a dead connection.
- TLS needs nothing beyond `TlsOptions`; TLS 1.2 is the minimum.
