<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

/**
 * $subscriptionIdentifier and $userProperties are MQTT 5 only. MQTT 5
 * subscription options (No Local, Retain As Published, Retain Handling) are
 * sent as zero and not exposed yet.
 */
final readonly class Subscribe implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  non-empty-array<string, int>  $filters  Topic filter => requested QoS.
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public int $packetId,
        public array $filters,
        public ?int $subscriptionIdentifier = null,
        public array $userProperties = [],
    ) {}
}
