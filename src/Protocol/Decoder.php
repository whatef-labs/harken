<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

use Whatef\Harken\Protocol\Packet\Packet;

interface Decoder
{
    /**
     * Feeds raw bytes and returns every packet completed by them. Partial
     * packets are buffered until the rest arrives.
     *
     * @return list<Packet>
     *
     * @throws ProtocolException on malformed or unsupported input.
     */
    public function push(string $bytes): array;
}
