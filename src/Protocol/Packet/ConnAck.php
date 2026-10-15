<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

final readonly class ConnAck implements Packet
{
    public const int ACCEPTED = 0;

    public function __construct(
        public int $returnCode,
        public bool $sessionPresent = false,
    ) {}

    public function accepted(): bool
    {
        return $this->returnCode === self::ACCEPTED;
    }
}
