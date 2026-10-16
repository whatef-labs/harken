<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use InvalidArgumentException;
use UnexpectedValueException;
use Whatef\Harken\Protocol\Properties;

/**
 * An inbound MQTT message as handed to handlers.
 *
 * The payload is the exact byte string received from the broker. Harken never
 * decodes or re-encodes it, so signatures over the raw bytes stay verifiable,
 * including after the message has been serialized onto a queue.
 */
final readonly class Message
{
    /**
     * @param  array<string, string>  $params  Values of `{name}` levels in the matched route.
     * @param  Properties  $properties  MQTT 5 message properties; always empty on MQTT 3.1.1.
     */
    public function __construct(
        public string $topic,
        public string $payload,
        public int $qos = 0,
        public bool $retain = false,
        public array $params = [],
        public Properties $properties = new Properties,
    ) {}

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * @param  array<string, string>  $params
     */
    public function withParams(array $params): self
    {
        return new self($this->topic, $this->payload, $this->qos, $this->retain, $params, $this->properties);
    }

    /**
     * Queue payloads are JSON, which cannot carry arbitrary bytes, so the
     * payload travels base64-encoded and is restored on unserialize.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'topic' => $this->topic,
            'payload' => base64_encode($this->payload),
            'qos' => $this->qos,
            'retain' => $this->retain,
            'params' => $this->params,
            'properties' => $this->properties->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $payload = base64_decode(is_string($data['payload'] ?? null) ? $data['payload'] : '', true);

        if ($payload === false || ! is_string($data['topic'] ?? null) || ! is_int($data['qos'] ?? null)) {
            throw new UnexpectedValueException('Malformed serialized Harken message.');
        }

        /** @var array<string, string> $params */
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        try {
            $properties = Properties::fromArray(is_array($data['properties'] ?? null) ? $data['properties'] : []);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedValueException('Malformed serialized Harken message.', 0, $e);
        }

        $this->topic = $data['topic'];
        $this->payload = $payload;
        $this->qos = $data['qos'];
        $this->retain = (bool) ($data['retain'] ?? false);
        $this->params = $params;
        $this->properties = $properties;
    }
}
