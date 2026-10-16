<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use Whatef\Harken\Protocol\ReasonCode;

/**
 * MQTT 3.1.1 return codes arrive mapped to their MQTT 5 reason codes.
 *
 * The other fields are MQTT 5 only. Null means the broker did not send the
 * property and the specification default applies (for example a Receive
 * Maximum of 65 535 and a Maximum QoS of 2).
 */
final readonly class ConnAck implements Packet
{
    /**
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public ReasonCode $reasonCode = ReasonCode::Success,
        public bool $sessionPresent = false,
        public ?int $sessionExpiryInterval = null,
        public ?int $receiveMaximum = null,
        public ?int $maximumQos = null,
        public ?bool $retainAvailable = null,
        public ?int $maximumPacketSize = null,
        public ?string $assignedClientIdentifier = null,
        public ?int $topicAliasMaximum = null,
        public ?string $reasonString = null,
        public array $userProperties = [],
        public ?bool $wildcardSubscriptionAvailable = null,
        public ?bool $subscriptionIdentifiersAvailable = null,
        public ?bool $sharedSubscriptionAvailable = null,
        public ?int $serverKeepAlive = null,
        public ?string $responseInformation = null,
        public ?string $serverReference = null,
    ) {}

    public function accepted(): bool
    {
        return $this->reasonCode === ReasonCode::Success;
    }
}
