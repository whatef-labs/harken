# Spike 02 — Shared subscriptions from MQTT 3.1.1 clients on EMQX 5

**Question.** Does EMQX 5 accept `$share/<group>/<filter>` subscriptions from MQTT **3.1.1** clients and spread messages across the group, one member per message? What happens to messages a member received but never acknowledged when it crashes?

**Verdict: pending.** The script is done and passes a local smoke run. The broker behaviour in question can only be answered by EMQX itself (test VM).

| | |
|---|---|
| Script | [`spikes/02-shared-subscription.php`](../../spikes/02-shared-subscription.php) |
| Run against EMQX | `SPIKE_MQTT_HOST=<vm-ip> php spikes/02-shared-subscription.php` |

## Experiments

| # | Setup | Expectation to confirm |
|---|---|---|
| E1 | members a, b on `$share/g/spike/<run>/share/+` (QoS 1) plus one plain subscriber; 1 000 QoS 1 publishes over 10 topics | SUBACK grants QoS 1 (not 0x80) to a 3.1.1 client; a + b = 1 000 with no message delivered to both; the plain subscriber gets all 1 000 |
| E2 | member a stops sending PUBACK, receives some messages, then drops its TCP connection without DISCONNECT; 100 more messages follow | Whether EMQX redispatches a's unacknowledged QoS 1 messages to b, and whether any message is seen by nobody |

The local smoke run (against a broker double that round-robins `$share` groups) passes E1 with a 500 / 500 split and exercises the E2 code path. The double does not model redispatch, so it does not answer E2.

## Why E2 matters

The runtime gives each replica a random client id (C4) and a clean session by default. With a clean session, an unacknowledged message dies with the connection. Two possible routes to "a message being handled when a replica crashes is not lost" (C-06, X-03):

1. **Redispatch inside the shared group.** EMQX 5 is understood to redispatch the unacked QoS 1/2 messages of a terminated shared-subscription session to the remaining members. This is unverified; E2 checks it for 3.1.1 clients with a clean session.
2. **Persistent sessions.** Each replica gets a *stable* client id (for example from an ordinal or the hostname of a stateful replica) with `clean_session = 0`. Spike 01 E3 checks redelivery.

The outcome decides the default QoS 1 acknowledgement policy (see the README of this folder).

## Alternatives if `$share` is refused for 3.1.1 clients

- Topic partitioning: each replica subscribes to a deterministic slice (`device/{id}` hashed by replica index). This needs a stable replica index and coordination when the replica count changes.
- An MQTT 5 codec behind the `Codec` interface, using shared subscriptions as specified there.
