<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

final readonly class PubAck implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     */
    public function __construct(public int $packetId) {}
}
