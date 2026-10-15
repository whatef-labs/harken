<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

final readonly class Subscribe implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  non-empty-array<string, int>  $filters  Topic filter => requested QoS.
     */
    public function __construct(
        public int $packetId,
        public array $filters,
    ) {}
}
