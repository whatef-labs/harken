<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use Whatef\Harken\Protocol\Properties;

/**
 * $properties and $topicAlias are MQTT 5 only; the 3.1.1 codec drops them.
 */
final readonly class Publish implements Packet
{
    /**
     * @param  int<1, 65535>|null  $packetId  Required for QoS 1 and 2, null for QoS 0.
     */
    public function __construct(
        public string $topic,
        public string $payload,
        public int $qos = 0,
        public bool $retain = false,
        public bool $duplicate = false,
        public ?int $packetId = null,
        public Properties $properties = new Properties,
        public ?int $topicAlias = null,
    ) {}

    public function asDuplicate(): self
    {
        return new self($this->topic, $this->payload, $this->qos, $this->retain, true, $this->packetId, $this->properties, $this->topicAlias);
    }
}
