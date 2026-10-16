<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use Whatef\Harken\Protocol\ReasonCode;

/**
 * Everything after $packetId is MQTT 5 only; on 3.1.1 a PUBACK always means
 * success.
 */
final readonly class PubAck implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public int $packetId,
        public ReasonCode $reasonCode = ReasonCode::Success,
        public ?string $reasonString = null,
        public array $userProperties = [],
    ) {}
}
