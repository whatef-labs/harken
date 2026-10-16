<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

/**
 * MQTT 5 reason codes (§2.4). Values below 0x80 report success, values from
 * 0x80 up report failure.
 *
 * Some values have several names in the specification depending on the
 * packet: 0x00 is also "Normal disconnection" (DISCONNECT) and "Granted QoS 0"
 * (SUBACK). The MQTT 3.1.1 codec maps its CONNACK and SUBACK return codes onto
 * these, so the runtime handles one set of codes for both versions.
 */
enum ReasonCode: int
{
    case Success = 0x00;
    case GrantedQos1 = 0x01;
    case GrantedQos2 = 0x02;
    case DisconnectWithWillMessage = 0x04;
    case NoMatchingSubscribers = 0x10;
    case NoSubscriptionExisted = 0x11;
    case ContinueAuthentication = 0x18;
    case ReAuthenticate = 0x19;
    case UnspecifiedError = 0x80;
    case MalformedPacket = 0x81;
    case ProtocolError = 0x82;
    case ImplementationSpecificError = 0x83;
    case UnsupportedProtocolVersion = 0x84;
    case ClientIdentifierNotValid = 0x85;
    case BadUserNameOrPassword = 0x86;
    case NotAuthorized = 0x87;
    case ServerUnavailable = 0x88;
    case ServerBusy = 0x89;
    case Banned = 0x8A;
    case ServerShuttingDown = 0x8B;
    case BadAuthenticationMethod = 0x8C;
    case KeepAliveTimeout = 0x8D;
    case SessionTakenOver = 0x8E;
    case TopicFilterInvalid = 0x8F;
    case TopicNameInvalid = 0x90;
    case PacketIdentifierInUse = 0x91;
    case PacketIdentifierNotFound = 0x92;
    case ReceiveMaximumExceeded = 0x93;
    case TopicAliasInvalid = 0x94;
    case PacketTooLarge = 0x95;
    case MessageRateTooHigh = 0x96;
    case QuotaExceeded = 0x97;
    case AdministrativeAction = 0x98;
    case PayloadFormatInvalid = 0x99;
    case RetainNotSupported = 0x9A;
    case QosNotSupported = 0x9B;
    case UseAnotherServer = 0x9C;
    case ServerMoved = 0x9D;
    case SharedSubscriptionsNotSupported = 0x9E;
    case ConnectionRateExceeded = 0x9F;
    case MaximumConnectTime = 0xA0;
    case SubscriptionIdentifiersNotSupported = 0xA1;
    case WildcardSubscriptionsNotSupported = 0xA2;

    public function isError(): bool
    {
        return $this->value >= 0x80;
    }

    /**
     * The MQTT 5 equivalent of an MQTT 3.1.1 CONNACK return code (§3.2.2.3),
     * or null when the code is not defined.
     */
    public static function fromConnAckReturnCode(int $code): ?self
    {
        return match ($code) {
            0 => self::Success,
            1 => self::UnsupportedProtocolVersion,
            2 => self::ClientIdentifierNotValid,
            3 => self::ServerUnavailable,
            4 => self::BadUserNameOrPassword,
            5 => self::NotAuthorized,
            default => null,
        };
    }

    /**
     * The MQTT 3.1.1 CONNACK return code for this reason, or null when 3.1.1
     * has none.
     *
     * @return int<0, 5>|null
     */
    public function connAckReturnCode(): ?int
    {
        return match ($this) {
            self::Success => 0,
            self::UnsupportedProtocolVersion => 1,
            self::ClientIdentifierNotValid => 2,
            self::ServerUnavailable => 3,
            self::BadUserNameOrPassword => 4,
            self::NotAuthorized => 5,
            default => null,
        };
    }

    /**
     * For logs, e.g. "0x87 not authorized".
     */
    public function describe(): string
    {
        $words = strtolower((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $this->name));

        return sprintf('0x%02X %s', $this->value, $words);
    }
}
