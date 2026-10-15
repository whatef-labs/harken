<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

use Whatef\Harken\Protocol\Packet\Packet;

/**
 * Turns packets into bytes and back. Knows nothing about sockets or the loop.
 */
interface Codec
{
    public function version(): ProtocolVersion;

    /**
     * @throws ProtocolException when the packet cannot be represented by this codec.
     */
    public function encode(Packet $packet): string;

    /**
     * Creates a stateful decoder for one connection's inbound byte stream.
     */
    public function decoder(): Decoder;
}
