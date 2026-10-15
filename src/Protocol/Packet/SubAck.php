<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

final readonly class SubAck implements Packet
{
    public const int FAILURE = 0x80;

    /**
     * @param  int<1, 65535>  $packetId
     * @param  list<int>  $returnCodes  Granted QoS per filter, or 0x80 on failure.
     */
    public function __construct(
        public int $packetId,
        public array $returnCodes,
    ) {}

    public function failed(): bool
    {
        return in_array(self::FAILURE, $this->returnCodes, true);
    }
}
