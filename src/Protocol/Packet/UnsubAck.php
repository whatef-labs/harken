<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use Whatef\Harken\Protocol\ReasonCode;

final readonly class UnsubAck implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  list<ReasonCode>  $reasonCodes  MQTT 5: one per filter, in UNSUBSCRIBE order. Always empty on 3.1.1.
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public int $packetId,
        public array $reasonCodes = [],
        public ?string $reasonString = null,
        public array $userProperties = [],
    ) {}
}
