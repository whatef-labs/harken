# Spike 02 — Shared subscriptions from MQTT 3.1.1 clients on EMQX 5

**Question.** Does EMQX 5 accept `$share/<group>/<filter>` subscriptions from MQTT **3.1.1** clients and spread messages across the group, one member per message? What happens to messages a member received but never acknowledged when it crashes?

**Verdict: pass.** An MQTT 5 codec is not needed for horizontal scaling. When a member crashes, its unacknowledged messages move to the remaining members.

| | |
|---|---|
| Script | [`spikes/02-shared-subscription.php`](../../spikes/02-shared-subscription.php) |
| Environment | test VM, EMQX 5.8.9 defaults (dispatch strategy `round_robin`), PHP 8.5.11, clean sessions, random client ids |
| Reproduce | `SPIKE_MQTT_HOST=127.0.0.1 php spikes/02-shared-subscription.php` |

## Results

| # | Experiment | Result |
|---|---|---|
| E1 | members a and b subscribe `$share/g/spike/<run>/share/+` (QoS 1); one plain subscriber on the same filter; 1 000 QoS 1 publishes over 10 topics | SUBACK grants **QoS 1** to both 3.1.1 members. a: 500, b: 500, **0 delivered to both**; plain subscriber: 1 000 |
| E2 | a stops sending PUBACK; 200 publishes; a's TCP connection is dropped without DISCONNECT; 100 more publishes | a received 32 (its `max_inflight`) and acked none. After the crash **all 32 of them were delivered to b**; b received all 300; **no message was lost** |

## Findings

1. **EMQX 5 supports `$share` for MQTT 3.1.1 clients.** The filter passes binsoul's client-side validation, and Harken's `->shared('group')` maps directly to it.
2. **Exactly one member per message** under the default round-robin strategy; non-shared subscribers are unaffected.
3. **Redispatch on member failure.** Messages a member holds unacknowledged (in flight) or queued are handed to other members when its session ends, even with clean sessions. This is what makes "acknowledge after the handler" protect a message being handled when a replica crashes. It works without persistent sessions or stable client ids, so random per-replica ids (C4) stay.
4. **A stalled member holds its share.** A member that is alive but never acknowledges stops receiving after `max_inflight` messages, and messages already assigned to it wait in its queue until it dies. A stuck runtime must therefore be detected (health check) and restarted, not merely logged.
5. **Redispatch means duplicates.** A message whose handler finished but whose PUBACK was lost in the crash is delivered again to another member. Delivery is at least once, so handlers on these topics should be idempotent.

## Design consequences

- `->shared()` stays as designed; MQTT 5 is not required for H1/H2.
- The default QoS 1 policy can be "acknowledge after the handler" for shared routes. See the [index](README.md) for the full recommendation.
- `harken:status` / the health check must flag a runtime whose loop is stuck, so the process manager restarts it and EMQX redispatches its messages.
