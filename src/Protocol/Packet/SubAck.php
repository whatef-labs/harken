<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use Whatef\Harken\Protocol\ReasonCode;

final readonly class SubAck implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  non-empty-list<ReasonCode>  $reasonCodes  One per filter, in SUBSCRIBE order: the granted QoS
     *                                                   (Success = QoS 0, GrantedQos1, GrantedQos2) or an error.
     *                                                   MQTT 3.1.1's failure code 0x80 arrives as UnspecifiedError.
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public int $packetId,
        public array $reasonCodes,
        public ?string $reasonString = null,
        public array $userProperties = [],
    ) {}

    public function failed(): bool
    {
        foreach ($this->reasonCodes as $code) {
            if ($code->isError()) {
                return true;
            }
        }

        return false;
    }
}
