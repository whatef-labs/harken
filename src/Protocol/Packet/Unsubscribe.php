<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

final readonly class Unsubscribe implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  non-empty-list<string>  $filters
     */
    public function __construct(
        public int $packetId,
        public array $filters,
    ) {}
}
