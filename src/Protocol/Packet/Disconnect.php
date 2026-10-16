<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use Whatef\Harken\Protocol\ReasonCode;

/**
 * All fields are MQTT 5 only. On MQTT 5 the broker may send DISCONNECT too,
 * for example with SessionTakenOver when another client connects with the
 * same client id.
 */
final readonly class Disconnect implements Packet
{
    /**
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public ReasonCode $reasonCode = ReasonCode::Success,
        public ?int $sessionExpiryInterval = null,
        public ?string $reasonString = null,
        public ?string $serverReference = null,
        public array $userProperties = [],
    ) {}
}
