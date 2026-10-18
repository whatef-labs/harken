<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Outbox;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use Whatef\Harken\Protocol\Properties;

/**
 * A publish request handed to the runtime through a Redis stream entry.
 * Redis stream values are binary-safe, so the payload is stored as-is. MQTT 5
 * properties, when set, travel as one JSON field.
 */
final readonly class OutboxMessage
{
    public function __construct(
        public string $topic,
        public string $payload,
        public int $qos = 0,
        public bool $retain = false,
        public ?int $enqueuedAtMs = null,
        public Properties $properties = new Properties,
    ) {}

    /**
     * Seconds left of the message expiry interval once the time since the
     * entry was queued is taken off (as a broker does, MQTT 5 3.3.2.3.3),
     * rounded up: null when the message does not expire, 0 once it expired.
     */
    public function expiryLeft(float $nowMs): ?int
    {
        $interval = $this->properties->messageExpiryInterval;

        if ($interval === null || $this->enqueuedAtMs === null) {
            return $interval;
        }

        $leftMs = $interval * 1000 - ($nowMs - $this->enqueuedAtMs);

        return $leftMs <= 0 ? 0 : min($interval, (int) ceil($leftMs / 1000));
    }

    /**
     * The same message with another message expiry interval.
     */
    public function withExpiry(int $seconds): self
    {
        $properties = Properties::fromArray(['message_expiry_interval' => $seconds] + $this->properties->toArray());

        return new self($this->topic, $this->payload, $this->qos, $this->retain, $this->enqueuedAtMs, $properties);
    }

    /**
     * @return array<string, string>
     */
    public function toFields(): array
    {
        $fields = [
            'topic' => $this->topic,
            'payload' => $this->payload,
            'qos' => (string) $this->qos,
            'retain' => $this->retain ? '1' : '0',
            'enqueued_at' => (string) ($this->enqueuedAtMs ?? (int) (microtime(true) * 1000)),
        ];

        if (! $this->properties->isEmpty()) {
            $fields['properties'] = json_encode($this->properties->toArray(), JSON_THROW_ON_ERROR);
        }

        return $fields;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    public static function fromFields(array $fields): self
    {
        $topic = $fields['topic'] ?? null;
        $payload = $fields['payload'] ?? null;
        $qos = $fields['qos'] ?? null;

        if (! is_string($topic) || $topic === '' || ! is_string($payload) || ! in_array($qos, ['0', '1'], true)) {
            throw new UnexpectedValueException('Malformed outbox entry.');
        }

        $enqueuedAt = $fields['enqueued_at'] ?? null;
        $properties = $fields['properties'] ?? null;

        try {
            $properties = is_string($properties)
                ? Properties::fromArray((array) json_decode($properties, true, flags: JSON_THROW_ON_ERROR))
                : new Properties;
        } catch (InvalidArgumentException|JsonException $e) {
            throw new UnexpectedValueException('Malformed outbox entry properties.', 0, $e);
        }

        return new self(
            topic: $topic,
            payload: $payload,
            qos: (int) $qos,
            retain: ($fields['retain'] ?? '0') === '1',
            enqueuedAtMs: is_string($enqueuedAt) && ctype_digit($enqueuedAt) ? (int) $enqueuedAt : null,
            properties: $properties,
        );
    }
}
