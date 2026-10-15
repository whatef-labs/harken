<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Outbox;

use UnexpectedValueException;

/**
 * A publish request handed to the runtime through a Redis stream entry.
 * Redis stream values are binary-safe, so the payload is stored as-is.
 */
final readonly class OutboxMessage
{
    public function __construct(
        public string $topic,
        public string $payload,
        public int $qos = 0,
        public bool $retain = false,
        public ?int $enqueuedAtMs = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toFields(): array
    {
        return [
            'topic' => $this->topic,
            'payload' => $this->payload,
            'qos' => (string) $this->qos,
            'retain' => $this->retain ? '1' : '0',
            'enqueued_at' => (string) ($this->enqueuedAtMs ?? (int) (microtime(true) * 1000)),
        ];
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

        return new self(
            topic: $topic,
            payload: $payload,
            qos: (int) $qos,
            retain: ($fields['retain'] ?? '0') === '1',
            enqueuedAtMs: is_string($enqueuedAt) && ctype_digit($enqueuedAt) ? (int) $enqueuedAt : null,
        );
    }
}
