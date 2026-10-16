<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use Whatef\Harken\Protocol\Packet\ConnAck;

/**
 * What the broker announced in CONNACK (MQTT 5). MQTT 3.1.1 brokers announce
 * nothing, so the specification defaults apply.
 */
final readonly class BrokerLimits
{
    public function __construct(
        public int $receiveMaximum = 65535,
        public int $maximumQos = 2,
        public bool $retainAvailable = true,
        public ?int $maximumPacketSize = null,
        public bool $wildcardSubscriptionAvailable = true,
        public bool $sharedSubscriptionAvailable = true,
        public ?int $serverKeepAlive = null,
    ) {}

    public static function fromConnAck(ConnAck $ack): self
    {
        return new self(
            receiveMaximum: $ack->receiveMaximum ?? 65535,
            maximumQos: $ack->maximumQos ?? 2,
            retainAvailable: $ack->retainAvailable ?? true,
            maximumPacketSize: $ack->maximumPacketSize,
            wildcardSubscriptionAvailable: $ack->wildcardSubscriptionAvailable ?? true,
            sharedSubscriptionAvailable: $ack->sharedSubscriptionAvailable ?? true,
            serverKeepAlive: $ack->serverKeepAlive,
        );
    }
}
