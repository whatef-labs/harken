<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use RuntimeException;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Protocol\ReasonCode;

/**
 * The broker answered CONNECT with an error reason code.
 */
final class ConnectionRefused extends RuntimeException
{
    /**
     * Refusals that retrying with the same settings cannot fix.
     */
    private const array PERMANENT = [
        ReasonCode::MalformedPacket,
        ReasonCode::ProtocolError,
        ReasonCode::UnsupportedProtocolVersion,
        ReasonCode::ClientIdentifierNotValid,
        ReasonCode::BadUserNameOrPassword,
        ReasonCode::NotAuthorized,
        ReasonCode::Banned,
        ReasonCode::BadAuthenticationMethod,
        ReasonCode::PacketTooLarge,
    ];

    public function __construct(
        public readonly ReasonCode $reasonCode,
        public readonly ProtocolVersion $protocol,
        public readonly ?string $reasonString = null,
    ) {
        parent::__construct(sprintf(
            'The broker refused the MQTT %s connection: %s%s.',
            $protocol->value,
            $reasonCode->describe(),
            $reasonString === null ? '' : " ({$reasonString})",
        ), $reasonCode->value);
    }

    public static function fromConnAck(ConnAck $ack, ProtocolVersion $protocol): self
    {
        return new self($ack->reasonCode, $protocol, $ack->reasonString);
    }

    public function isPermanent(): bool
    {
        return in_array($this->reasonCode, self::PERMANENT, true);
    }
}
