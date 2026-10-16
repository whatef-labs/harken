<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Mqtt5;

/**
 * MQTT 5 property identifiers (§2.2.2.2).
 *
 * @internal
 */
enum Property: int
{
    case PayloadFormatIndicator = 0x01;
    case MessageExpiryInterval = 0x02;
    case ContentType = 0x03;
    case ResponseTopic = 0x08;
    case CorrelationData = 0x09;
    case SubscriptionIdentifier = 0x0B;
    case SessionExpiryInterval = 0x11;
    case AssignedClientIdentifier = 0x12;
    case ServerKeepAlive = 0x13;
    case AuthenticationMethod = 0x15;
    case AuthenticationData = 0x16;
    case RequestProblemInformation = 0x17;
    case WillDelayInterval = 0x18;
    case RequestResponseInformation = 0x19;
    case ResponseInformation = 0x1A;
    case ServerReference = 0x1C;
    case ReasonString = 0x1F;
    case ReceiveMaximum = 0x21;
    case TopicAliasMaximum = 0x22;
    case TopicAlias = 0x23;
    case MaximumQos = 0x24;
    case RetainAvailable = 0x25;
    case UserProperty = 0x26;
    case MaximumPacketSize = 0x27;
    case WildcardSubscriptionAvailable = 0x28;
    case SubscriptionIdentifierAvailable = 0x29;
    case SharedSubscriptionAvailable = 0x2A;

    public function type(): PropertyType
    {
        return match ($this) {
            self::PayloadFormatIndicator,
            self::RequestProblemInformation,
            self::RequestResponseInformation,
            self::MaximumQos,
            self::RetainAvailable,
            self::WildcardSubscriptionAvailable,
            self::SubscriptionIdentifierAvailable,
            self::SharedSubscriptionAvailable => PropertyType::Byte,
            self::ServerKeepAlive,
            self::ReceiveMaximum,
            self::TopicAliasMaximum,
            self::TopicAlias => PropertyType::TwoByteInteger,
            self::MessageExpiryInterval,
            self::SessionExpiryInterval,
            self::WillDelayInterval,
            self::MaximumPacketSize => PropertyType::FourByteInteger,
            self::SubscriptionIdentifier => PropertyType::VariableByteInteger,
            self::ContentType,
            self::ResponseTopic,
            self::AssignedClientIdentifier,
            self::AuthenticationMethod,
            self::ResponseInformation,
            self::ServerReference,
            self::ReasonString => PropertyType::Utf8String,
            self::CorrelationData,
            self::AuthenticationData => PropertyType::BinaryData,
            self::UserProperty => PropertyType::Utf8StringPair,
        };
    }

    /**
     * Properties for which 0 is a protocol error.
     */
    public function zeroIsInvalid(): bool
    {
        return match ($this) {
            self::ReceiveMaximum, self::MaximumPacketSize, self::TopicAlias, self::SubscriptionIdentifier => true,
            default => false,
        };
    }

    /**
     * Words for error messages, e.g. "receive maximum".
     */
    public function label(): string
    {
        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $this->name));
    }
}
