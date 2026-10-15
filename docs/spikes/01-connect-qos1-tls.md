# Spike 01 — binsoul/net-mqtt + react/socket: connect, QoS 1, TLS

**Question.** Do binsoul/net-mqtt packets over a react/socket connection give a working MQTT 3.1.1 client (connect → subscribe → QoS 1 publish → PUBACK, TLS on 8883) with the runtime in control of when inbound QoS 1 messages are acknowledged?

**Verdict: pending.** The scripts are done and pass a local smoke run against an in-process broker double. The verdict needs a run against EMQX 5.8 (test VM).

| | |
|---|---|
| Scripts | [`spikes/01-connect-qos1-tls.php`](../../spikes/01-connect-qos1-tls.php), client: [`spikes/MiniClient.php`](../../spikes/MiniClient.php) |
| Broker | **EMQX run pending.** Smoke run: a minimal broker double (CONNECT, SUBSCRIBE incl. `$share`, QoS 0/1, retain, PING; no sessions, no keepalive enforcement) |
| Run against EMQX | `docker compose -f docker/compose.yml up -d`, then `SPIKE_MQTT_HOST=<vm-ip> php spikes/01-connect-qos1-tls.php` |

## Established so far

From reading `binsoul/net-mqtt` 2.0.1:

- `IncomingPublishFlow::start()` returns the PUBACK packet and marks the message delivered in the same call. The **caller** decides when that PUBACK is written. Harken therefore uses only binsoul's packet classes and `StreamParser` behind its own `Codec` interface and does its own QoS 1 bookkeeping (`PacketIdPool`). This keeps "acknowledge after the handler" possible and keeps binsoul types out of the runtime.
- `SUBSCRIBE` filter validation is "non-empty UTF-8 string", so `$share/…` filters pass the client side.
- Typed constants (`public const int`) require PHP ≥ 8.3, which is consistent with `^8.4`.

From the local smoke run (Windows, PHP 8.5.11):

| | Result |
|---|---|
| Codec over a real TCP socket | CONNACK, SUBACK `[1]`, QoS 1 echo with binary payload intact |
| 10 000 × 400 B QoS 1, window 100 | ≈32 000 msg/s, all PUBACKed and all echoed |
| Retain / clear retained | a new subscriber gets the retained value with the retain flag; after an empty retained publish it gets nothing |
| TLS with private CA (`cafile`) | connects; system CAs only → `certificate verify failed` (as expected) |
| Mutual TLS with client cert | connects; without client cert → closed before CONNACK (as expected) |

These numbers show the client plumbing works (framing, PUBACK correlation, react/socket TLS context with TLS ≥ 1.2). They say nothing about EMQX behaviour or real latency.

## To be answered by the EMQX run

| # | Check | Why it matters |
|---|---|---|
| E1 | connect / subscribe / own QoS 1 echo | baseline interop with EMQX 5.8 |
| E2 | PUBACK RTT p50/p99, pipelined throughput | C-04, P1; input to the B1 budget |
| E3 | inbound QoS 1 left unacked, client killed, reconnect: **persistent vs clean session** | Whether "ACK after handler" protects anything. Expectation: redelivery (DUP=1) only with `clean_session = 0` and the same client id, which conflicts with per-replica random client ids (C4). See spike 02 E2 for redelivery through shared subscriptions instead |
| E4 | retained publish and empty-payload clear | P2 |
| E5 | TLS 8883 with private CA, mTLS 8884, failure modes | C5, X-06 acceptance |
| E6 | keepalive 4 s: when EMQX drops a silent client; a client pinging at keepalive/2 | C7: confirms the "1.5 × keepalive" grace before choosing the ping interval |

## Alternatives if it fails

- Interop problems in packet encoding: fix them in `BinsoulCodec` or replace the codec behind the `Codec` interface. `simps/mqtt`'s protocol classes are pure PHP and also cover MQTT 5.
- `binsoul/net-mqtt-client-react` as a ready-made client was the fallback in the design. It is not needed: it would hand PUBACK timing and reconnect policy to a library that Harken has to control anyway.
