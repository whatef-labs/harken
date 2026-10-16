<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Protocol\Wire\Framer;

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
     *
     * @param  int  $maximumPacketSize  Larger inbound packets are rejected as soon as
     *                                  their fixed header arrives.
     */
    public function decoder(int $maximumPacketSize = Framer::PROTOCOL_MAXIMUM): Decoder;
}
