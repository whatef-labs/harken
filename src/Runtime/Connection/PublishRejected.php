<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use RuntimeException;
use Whatef\Harken\Protocol\ReasonCode;

/**
 * A publish that the broker refused (MQTT 5 PUBACK error), or that Harken
 * did not send because it breaks a limit the broker announced.
 */
final class PublishRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $topic,
        public readonly ReasonCode $reasonCode,
    ) {
        parent::__construct($message, $reasonCode->value);
    }

    public static function byBroker(string $topic, ReasonCode $reasonCode, ?string $reasonString = null): self
    {
        return new self(sprintf(
            'The broker rejected the publish to [%s]: %s%s.',
            $topic,
            $reasonCode->describe(),
            $reasonString === null ? '' : " ({$reasonString})",
        ), $topic, $reasonCode);
    }

    public static function qosNotSupported(string $topic, int $qos, int $maximum): self
    {
        return new self("Cannot publish to [{$topic}] with QoS {$qos}: the broker allows at most QoS {$maximum}.", $topic, ReasonCode::QosNotSupported);
    }

    public static function retainNotSupported(string $topic): self
    {
        return new self("Cannot publish a retained message to [{$topic}]: the broker does not support retained messages.", $topic, ReasonCode::RetainNotSupported);
    }

    public static function tooLarge(string $topic, int $bytes, int $maximum): self
    {
        return new self("Cannot publish to [{$topic}]: the packet is {$bytes} bytes and the broker accepts at most {$maximum}.", $topic, ReasonCode::PacketTooLarge);
    }

    public static function queueFull(string $topic, int $limit): self
    {
        return new self("Cannot publish to [{$topic}]: {$limit} messages are already waiting for the connection.", $topic, ReasonCode::QuotaExceeded);
    }

    public static function unencodable(string $topic, string $reason): self
    {
        return new self("Cannot publish to [{$topic}]: {$reason}", $topic, ReasonCode::ImplementationSpecificError);
    }
}
