<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Mqtt5;

use InvalidArgumentException;
use Whatef\Harken\Protocol\Codec;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Protocol\Packet\PingReq;
use Whatef\Harken\Protocol\Packet\PingResp;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\Packet\SubAck;
use Whatef\Harken\Protocol\Packet\Subscribe;
use Whatef\Harken\Protocol\Packet\UnsubAck;
use Whatef\Harken\Protocol\Packet\Unsubscribe;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Protocol\Wire\Frame;
use Whatef\Harken\Protocol\Wire\Framer;
use Whatef\Harken\Protocol\Wire\Reader;
use Whatef\Harken\Protocol\Wire\Writer;

/**
 * MQTT 5.0 codec (OASIS Standard, 7 March 2019).
 *
 * Both directions are mapped so the same codec can drive a broker double in
 * tests. Not supported yet: QoS 2 flows (PUBREC, PUBREL, PUBCOMP), enhanced
 * authentication (AUTH and the authentication properties) and subscription
 * options other than the maximum QoS. Receiving any of them is a protocol
 * error, so they are never silently misread.
 */
final class Mqtt5Codec implements Codec
{
    private const int CONNECT = 1;

    private const int CONNACK = 2;

    private const int PUBLISH = 3;

    private const int PUBACK = 4;

    private const int PUBREC = 5;

    private const int PUBREL = 6;

    private const int PUBCOMP = 7;

    private const int SUBSCRIBE = 8;

    private const int SUBACK = 9;

    private const int UNSUBSCRIBE = 10;

    private const int UNSUBACK = 11;

    private const int PINGREQ = 12;

    private const int PINGRESP = 13;

    private const int DISCONNECT = 14;

    private const int AUTH = 15;

    /** Properties allowed per packet (§2.2.2.2, table 2-4). */
    private const array CONNECT_PROPERTIES = [
        Property::SessionExpiryInterval->value => true,
        Property::ReceiveMaximum->value => true,
        Property::MaximumPacketSize->value => true,
        Property::TopicAliasMaximum->value => true,
        Property::RequestResponseInformation->value => true,
        Property::RequestProblemInformation->value => true,
        Property::UserProperty->value => true,
        Property::AuthenticationMethod->value => true,
        Property::AuthenticationData->value => true,
    ];

    private const array WILL_PROPERTIES = [
        Property::WillDelayInterval->value => true,
        Property::PayloadFormatIndicator->value => true,
        Property::MessageExpiryInterval->value => true,
        Property::ContentType->value => true,
        Property::ResponseTopic->value => true,
        Property::CorrelationData->value => true,
        Property::UserProperty->value => true,
    ];

    private const array CONNACK_PROPERTIES = [
        Property::SessionExpiryInterval->value => true,
        Property::ReceiveMaximum->value => true,
        Property::MaximumQos->value => true,
        Property::RetainAvailable->value => true,
        Property::MaximumPacketSize->value => true,
        Property::AssignedClientIdentifier->value => true,
        Property::TopicAliasMaximum->value => true,
        Property::ReasonString->value => true,
        Property::UserProperty->value => true,
        Property::WildcardSubscriptionAvailable->value => true,
        Property::SubscriptionIdentifierAvailable->value => true,
        Property::SharedSubscriptionAvailable->value => true,
        Property::ServerKeepAlive->value => true,
        Property::ResponseInformation->value => true,
        Property::ServerReference->value => true,
        Property::AuthenticationMethod->value => true,
        Property::AuthenticationData->value => true,
    ];

    private const array PUBLISH_PROPERTIES = [
        Property::PayloadFormatIndicator->value => true,
        Property::MessageExpiryInterval->value => true,
        Property::TopicAlias->value => true,
        Property::ResponseTopic->value => true,
        Property::CorrelationData->value => true,
        Property::UserProperty->value => true,
        Property::SubscriptionIdentifier->value => true,
        Property::ContentType->value => true,
    ];

    /** PUBACK, SUBACK and UNSUBACK. */
    private const array ACK_PROPERTIES = [
        Property::ReasonString->value => true,
        Property::UserProperty->value => true,
    ];

    private const array SUBSCRIBE_PROPERTIES = [
        Property::SubscriptionIdentifier->value => true,
        Property::UserProperty->value => true,
    ];

    private const array UNSUBSCRIBE_PROPERTIES = [
        Property::UserProperty->value => true,
    ];

    private const array DISCONNECT_PROPERTIES = [
        Property::SessionExpiryInterval->value => true,
        Property::ReasonString->value => true,
        Property::ServerReference->value => true,
        Property::UserProperty->value => true,
    ];

    private static ?Properties $noProperties = null;

    public function version(): ProtocolVersion
    {
        return ProtocolVersion::V5_0;
    }

    public function encode(Packet $packet): string
    {
        return match (true) {
            $packet instanceof Publish => $this->encodePublish($packet),
            $packet instanceof PubAck => $this->encodePubAck($packet),
            $packet instanceof Connect => $this->encodeConnect($packet),
            $packet instanceof ConnAck => $this->encodeConnAck($packet),
            $packet instanceof Subscribe => $this->encodeSubscribe($packet),
            $packet instanceof SubAck => $this->encodeSubAck($packet),
            $packet instanceof Unsubscribe => $this->encodeUnsubscribe($packet),
            $packet instanceof UnsubAck => $this->encodeUnsubAck($packet),
            $packet instanceof PingReq => Writer::packet(self::PINGREQ, 0, ''),
            $packet instanceof PingResp => Writer::packet(self::PINGRESP, 0, ''),
            $packet instanceof Disconnect => $this->encodeDisconnect($packet),
            default => throw new ProtocolException(sprintf('Unsupported packet %s.', $packet::class)),
        };
    }

    public function decoder(int $maximumPacketSize = Framer::PROTOCOL_MAXIMUM): Decoder
    {
        return new Mqtt5Decoder($this, new Framer($maximumPacketSize));
    }

    /**
     * @internal Used by Mqtt5Decoder.
     *
     * @throws ProtocolException
     */
    public function decode(Frame $frame): Packet
    {
        $flags = match ($frame->type) {
            self::PUBLISH => $frame->flags,
            self::PUBREL, self::SUBSCRIBE, self::UNSUBSCRIBE => 0b0010,
            default => 0,
        };

        if ($frame->flags !== $flags) {
            throw new ProtocolException(sprintf('Invalid fixed header flags 0x%X for packet type %d.', $frame->flags, $frame->type));
        }

        $reader = new Reader($frame->body);
        $length = strlen($frame->body);

        return match ($frame->type) {
            self::PUBLISH => $this->decodePublish($reader, $frame->flags),
            self::PUBACK => $this->decodePubAck($reader, $length),
            self::CONNECT => $this->decodeConnect($reader),
            self::CONNACK => $this->decodeConnAck($reader, $length),
            self::SUBSCRIBE => $this->decodeSubscribe($reader),
            self::SUBACK => $this->decodeSubAck($reader),
            self::UNSUBSCRIBE => $this->decodeUnsubscribe($reader),
            self::UNSUBACK => $this->decodeUnsubAck($reader),
            self::PINGREQ => self::empty($reader, 'PINGREQ', new PingReq),
            self::PINGRESP => self::empty($reader, 'PINGRESP', new PingResp),
            self::DISCONNECT => $this->decodeDisconnect($reader, $length),
            self::PUBREC, self::PUBREL, self::PUBCOMP => throw ProtocolException::protocolError('QoS 2 is not supported.'),
            self::AUTH => throw ProtocolException::protocolError('Enhanced authentication (AUTH) is not supported.'),
        };
    }

    // ------------------------------------------------------------- encoding

    private function encodeConnect(Connect $packet): string
    {
        $flags = ($packet->cleanSession ? 0x02 : 0)
            | ($packet->password !== null ? 0x40 : 0)
            | ($packet->username !== null ? 0x80 : 0);

        if ($packet->will !== null) {
            $flags |= 0x04 | self::qos($packet->will->qos) << 3 | ($packet->will->retain ? 0x20 : 0);
        }

        $properties = (new PropertyBlock)
            ->add(Property::SessionExpiryInterval, $packet->sessionExpiryInterval ?: null)
            ->add(Property::ReceiveMaximum, $packet->receiveMaximum)
            ->add(Property::MaximumPacketSize, $packet->maximumPacketSize)
            ->add(Property::TopicAliasMaximum, $packet->topicAliasMaximum ?: null)
            ->add(Property::RequestResponseInformation, $packet->requestResponseInformation ? 1 : null)
            ->add(Property::RequestProblemInformation, $packet->requestProblemInformation ? null : 0)
            ->userProperties($packet->userProperties);

        $writer = (new Writer)
            ->utf8('MQTT', 'protocol name')
            ->byte(5)
            ->byte($flags)
            ->uint16($packet->keepAlive)
            ->raw($properties->bytes())
            ->utf8($packet->clientId, 'client identifier');

        if ($packet->will !== null) {
            if ($packet->will->properties->subscriptionIdentifiers !== [] || $packet->will->topicAlias !== null) {
                throw new ProtocolException('A will message cannot carry subscription identifiers or a topic alias.');
            }

            $writer->raw($this->messageProperties($packet->will, $packet->willDelayInterval ?: null)->bytes())
                ->utf8(self::topicName($packet->will->topic, false), 'will topic')
                ->binary($packet->will->payload, 'will payload');
        }

        if ($packet->username !== null) {
            $writer->utf8($packet->username, 'user name');
        }

        if ($packet->password !== null) {
            $writer->binary($packet->password, 'password');
        }

        return Writer::packet(self::CONNECT, 0, $writer->bytes());
    }

    private function encodeConnAck(ConnAck $packet): string
    {
        $properties = (new PropertyBlock)
            ->add(Property::SessionExpiryInterval, $packet->sessionExpiryInterval)
            ->add(Property::ReceiveMaximum, $packet->receiveMaximum)
            ->add(Property::MaximumQos, $packet->maximumQos)
            ->add(Property::RetainAvailable, self::flag($packet->retainAvailable))
            ->add(Property::MaximumPacketSize, $packet->maximumPacketSize)
            ->add(Property::AssignedClientIdentifier, $packet->assignedClientIdentifier)
            ->add(Property::TopicAliasMaximum, $packet->topicAliasMaximum)
            ->add(Property::ReasonString, $packet->reasonString)
            ->userProperties($packet->userProperties)
            ->add(Property::WildcardSubscriptionAvailable, self::flag($packet->wildcardSubscriptionAvailable))
            ->add(Property::SubscriptionIdentifierAvailable, self::flag($packet->subscriptionIdentifiersAvailable))
            ->add(Property::SharedSubscriptionAvailable, self::flag($packet->sharedSubscriptionAvailable))
            ->add(Property::ServerKeepAlive, $packet->serverKeepAlive)
            ->add(Property::ResponseInformation, $packet->responseInformation)
            ->add(Property::ServerReference, $packet->serverReference);

        $writer = (new Writer)
            ->byte($packet->sessionPresent ? 1 : 0)
            ->byte($packet->reasonCode->value)
            ->raw($properties->bytes());

        return Writer::packet(self::CONNACK, 0, $writer->bytes());
    }

    private function encodePublish(Publish $packet): string
    {
        $qos = self::qos($packet->qos);

        if ($packet->duplicate && $qos === 0) {
            throw new ProtocolException('DUP must be 0 on a QoS 0 PUBLISH.');
        }

        $writer = (new Writer)->utf8(self::topicName($packet->topic, $packet->topicAlias !== null), 'topic');

        if ($qos > 0) {
            $writer->uint16(self::packetId($packet->packetId, 'A QoS 1 or 2 PUBLISH'));
        }

        $writer->raw($this->messageProperties($packet)->bytes())->raw($packet->payload);
        $flags = ($packet->duplicate ? 0x08 : 0) | $qos << 1 | ($packet->retain ? 0x01 : 0);

        return Writer::packet(self::PUBLISH, $flags, $writer->bytes());
    }

    private function encodePubAck(PubAck $packet): string
    {
        $writer = (new Writer)->uint16(self::packetId($packet->packetId, 'PUBACK'));
        $properties = self::ackProperties($packet->reasonString, $packet->userProperties);

        // The reason code and property length may be left out when they carry nothing (§3.4.2.1).
        if (! $properties->isEmpty()) {
            $writer->byte($packet->reasonCode->value)->raw($properties->bytes());
        } elseif ($packet->reasonCode !== ReasonCode::Success) {
            $writer->byte($packet->reasonCode->value);
        }

        return Writer::packet(self::PUBACK, 0, $writer->bytes());
    }

    private function encodeSubscribe(Subscribe $packet): string
    {
        $properties = (new PropertyBlock)
            ->add(Property::SubscriptionIdentifier, $packet->subscriptionIdentifier)
            ->userProperties($packet->userProperties);

        $writer = (new Writer)
            ->uint16(self::packetId($packet->packetId, 'SUBSCRIBE'))
            ->raw($properties->bytes());

        foreach ($packet->filters as $filter => $qos) {
            $writer->utf8(self::topicFilter((string) $filter), 'topic filter')->byte(self::qos($qos));
        }

        return Writer::packet(self::SUBSCRIBE, 0b0010, $writer->bytes());
    }

    private function encodeSubAck(SubAck $packet): string
    {
        return $this->encodeAckWithCodes(self::SUBACK, 'SUBACK', $packet->packetId, $packet->reasonCodes, $packet->reasonString, $packet->userProperties);
    }

    private function encodeUnsubscribe(Unsubscribe $packet): string
    {
        $writer = (new Writer)
            ->uint16(self::packetId($packet->packetId, 'UNSUBSCRIBE'))
            ->raw((new PropertyBlock)->userProperties($packet->userProperties)->bytes());

        foreach ($packet->filters as $filter) {
            $writer->utf8(self::topicFilter($filter), 'topic filter');
        }

        return Writer::packet(self::UNSUBSCRIBE, 0b0010, $writer->bytes());
    }

    private function encodeUnsubAck(UnsubAck $packet): string
    {
        return $this->encodeAckWithCodes(self::UNSUBACK, 'UNSUBACK', $packet->packetId, $packet->reasonCodes, $packet->reasonString, $packet->userProperties);
    }

    private function encodeDisconnect(Disconnect $packet): string
    {
        $properties = (new PropertyBlock)
            ->add(Property::SessionExpiryInterval, $packet->sessionExpiryInterval)
            ->add(Property::ReasonString, $packet->reasonString)
            ->add(Property::ServerReference, $packet->serverReference)
            ->userProperties($packet->userProperties);

        // Normal disconnection without properties is the two-byte form (§3.14.2.1).
        if ($properties->isEmpty()) {
            return Writer::packet(self::DISCONNECT, 0, $packet->reasonCode === ReasonCode::Success ? '' : chr($packet->reasonCode->value));
        }

        return Writer::packet(self::DISCONNECT, 0, chr($packet->reasonCode->value).$properties->bytes());
    }

    /**
     * @param  list<ReasonCode>  $codes
     * @param  array<string, string|list<string>>  $userProperties
     */
    private function encodeAckWithCodes(int $type, string $name, int $packetId, array $codes, ?string $reasonString, array $userProperties): string
    {
        if ($codes === []) {
            throw new ProtocolException("{$name} needs one reason code per topic filter.");
        }

        $writer = (new Writer)
            ->uint16(self::packetId($packetId, $name))
            ->raw(self::ackProperties($reasonString, $userProperties)->bytes());

        foreach ($codes as $code) {
            $writer->byte($code->value);
        }

        return Writer::packet($type, 0, $writer->bytes());
    }

    private function messageProperties(Publish $message, ?int $willDelayInterval = null): PropertyBlock
    {
        $properties = $message->properties;
        $block = (new PropertyBlock)
            ->add(Property::WillDelayInterval, $willDelayInterval)
            ->add(Property::PayloadFormatIndicator, $properties->payloadFormatIndicator ? 1 : null)
            ->add(Property::MessageExpiryInterval, $properties->messageExpiryInterval)
            ->add(Property::ContentType, $properties->contentType)
            ->add(Property::ResponseTopic, $properties->responseTopic)
            ->add(Property::CorrelationData, $properties->correlationData)
            ->add(Property::TopicAlias, $message->topicAlias)
            ->userProperties($properties->userProperties);

        foreach ($properties->subscriptionIdentifiers as $id) {
            $block->add(Property::SubscriptionIdentifier, $id);
        }

        return $block;
    }

    /**
     * @param  array<string, string|list<string>>  $userProperties
     */
    private static function ackProperties(?string $reasonString, array $userProperties): PropertyBlock
    {
        return (new PropertyBlock)
            ->add(Property::ReasonString, $reasonString)
            ->userProperties($userProperties);
    }

    // ------------------------------------------------------------- decoding

    private function decodeConnect(Reader $reader): Connect
    {
        $name = $reader->utf8('protocol name');
        $level = $reader->byte();

        if ($name !== 'MQTT' || $level !== 5) {
            throw new ProtocolException("Unsupported protocol [{$name}] level [{$level}].", ReasonCode::UnsupportedProtocolVersion);
        }

        $flags = $reader->byte();
        $hasWill = ($flags & 0x04) !== 0;
        $willQos = $flags >> 3 & 0x03;
        $willRetain = ($flags & 0x20) !== 0;

        if (($flags & 0x01) !== 0 || $willQos === 3 || (! $hasWill && ($willQos !== 0 || $willRetain))) {
            throw new ProtocolException(sprintf('Invalid CONNECT flags 0x%02X.', $flags));
        }

        $keepAlive = $reader->uint16();
        $properties = PropertySet::read($reader, self::CONNECT_PROPERTIES, 'CONNECT');
        self::rejectAuthentication($properties);
        $clientId = $reader->utf8('client identifier');
        $will = null;
        $willDelayInterval = 0;

        if ($hasWill) {
            $willProperties = PropertySet::read($reader, self::WILL_PROPERTIES, 'will properties');
            $topic = self::receivedTopicName($reader->utf8('will topic'), false);
            $will = new Publish($topic, $reader->binary(), $willQos, $willRetain, properties: self::properties($willProperties));
            $willDelayInterval = $willProperties->int(Property::WillDelayInterval) ?? 0;
        }

        $username = ($flags & 0x80) !== 0 ? $reader->utf8('user name') : null;
        $password = ($flags & 0x40) !== 0 ? $reader->binary() : null;
        $reader->assertAtEnd('CONNECT');

        return new Connect(
            clientId: $clientId,
            keepAlive: $keepAlive,
            cleanSession: ($flags & 0x02) !== 0,
            username: $username,
            password: $password,
            will: $will,
            sessionExpiryInterval: $properties->int(Property::SessionExpiryInterval) ?? 0,
            receiveMaximum: $properties->int(Property::ReceiveMaximum),
            maximumPacketSize: $properties->int(Property::MaximumPacketSize),
            topicAliasMaximum: $properties->int(Property::TopicAliasMaximum) ?? 0,
            requestResponseInformation: $properties->bool(Property::RequestResponseInformation) ?? false,
            requestProblemInformation: $properties->bool(Property::RequestProblemInformation) ?? true,
            userProperties: $properties->userPropertyMap(),
            willDelayInterval: $willDelayInterval,
        );
    }

    private function decodeConnAck(Reader $reader, int $length): ConnAck
    {
        $flags = $reader->byte();
        $code = $reader->byte();

        if ($length === 2) {
            // A broker without MQTT 5 answers a level 5 CONNECT in its own
            // format: two bytes and a 3.1.1 return code, usually 1 (§3.1.2.2).
            $reason = ReasonCode::fromConnAckReturnCode($code) ?? ReasonCode::tryFrom($code);

            if ($reason === null || ! $reason->isError()) {
                throw new ProtocolException('CONNACK without properties.');
            }

            return new ConnAck($reason);
        }

        if (($flags & 0xFE) !== 0) {
            throw new ProtocolException(sprintf('Invalid CONNACK flags 0x%02X.', $flags));
        }

        $reason = self::reason($code);
        $properties = PropertySet::read($reader, self::CONNACK_PROPERTIES, 'CONNACK');
        self::rejectAuthentication($properties);
        $reader->assertAtEnd('CONNACK');

        return new ConnAck(
            reasonCode: $reason,
            sessionPresent: ($flags & 0x01) === 1,
            sessionExpiryInterval: $properties->int(Property::SessionExpiryInterval),
            receiveMaximum: $properties->int(Property::ReceiveMaximum),
            maximumQos: $properties->int(Property::MaximumQos),
            retainAvailable: $properties->bool(Property::RetainAvailable),
            maximumPacketSize: $properties->int(Property::MaximumPacketSize),
            assignedClientIdentifier: $properties->string(Property::AssignedClientIdentifier),
            topicAliasMaximum: $properties->int(Property::TopicAliasMaximum),
            reasonString: $properties->string(Property::ReasonString),
            userProperties: $properties->userPropertyMap(),
            wildcardSubscriptionAvailable: $properties->bool(Property::WildcardSubscriptionAvailable),
            subscriptionIdentifiersAvailable: $properties->bool(Property::SubscriptionIdentifierAvailable),
            sharedSubscriptionAvailable: $properties->bool(Property::SharedSubscriptionAvailable),
            serverKeepAlive: $properties->int(Property::ServerKeepAlive),
            responseInformation: $properties->string(Property::ResponseInformation),
            serverReference: $properties->string(Property::ServerReference),
        );
    }

    private function decodePublish(Reader $reader, int $flags): Publish
    {
        $qos = $flags >> 1 & 0x03;

        if ($qos === 3) {
            throw new ProtocolException('PUBLISH with QoS 3.');
        }

        $topic = $reader->utf8('topic');
        $packetId = $qos > 0 ? self::receivedPacketId($reader->uint16()) : null;
        $properties = PropertySet::read($reader, self::PUBLISH_PROPERTIES, 'PUBLISH');
        $alias = $properties->int(Property::TopicAlias);

        return new Publish(
            topic: self::receivedTopicName($topic, $alias !== null),
            payload: $reader->rest(),
            qos: $qos,
            retain: ($flags & 0x01) === 1,
            duplicate: ($flags & 0x08) !== 0,
            packetId: $packetId,
            properties: self::properties($properties),
            topicAlias: $alias,
        );
    }

    private function decodePubAck(Reader $reader, int $length): PubAck
    {
        $packetId = self::receivedPacketId($reader->uint16());

        // Reason code and property length are left out when they carry nothing (§3.4.2.1).
        if ($length === 2) {
            return new PubAck($packetId);
        }

        $reason = self::reason($reader->byte());

        if ($length === 3) {
            return new PubAck($packetId, $reason);
        }

        $properties = PropertySet::read($reader, self::ACK_PROPERTIES, 'PUBACK');
        $reader->assertAtEnd('PUBACK');

        return new PubAck($packetId, $reason, $properties->string(Property::ReasonString), $properties->userPropertyMap());
    }

    private function decodeSubscribe(Reader $reader): Subscribe
    {
        $packetId = self::receivedPacketId($reader->uint16());
        $properties = PropertySet::read($reader, self::SUBSCRIBE_PROPERTIES, 'SUBSCRIBE');

        if (count($properties->subscriptionIdentifiers) > 1) {
            throw ProtocolException::protocolError('SUBSCRIBE carries more than one subscription identifier.');
        }

        $filters = [];

        while (! $reader->atEnd()) {
            $filter = self::receivedTopicFilter($reader->utf8('topic filter'));
            $options = $reader->byte();

            if (($options & 0xC0) !== 0 || ($options & 0x03) === 3 || ($options >> 4 & 0x03) === 3) {
                throw new ProtocolException(sprintf('Invalid subscription options 0x%02X.', $options));
            }

            $filters[$filter] = $options & 0x03;
        }

        if ($filters === []) {
            throw ProtocolException::protocolError('SUBSCRIBE without topic filters.');
        }

        return new Subscribe($packetId, $filters, $properties->subscriptionIdentifiers[0] ?? null, $properties->userPropertyMap());
    }

    private function decodeSubAck(Reader $reader): SubAck
    {
        $packetId = self::receivedPacketId($reader->uint16());
        $properties = PropertySet::read($reader, self::ACK_PROPERTIES, 'SUBACK');

        return new SubAck($packetId, self::reasonCodes($reader, 'SUBACK'), $properties->string(Property::ReasonString), $properties->userPropertyMap());
    }

    private function decodeUnsubscribe(Reader $reader): Unsubscribe
    {
        $packetId = self::receivedPacketId($reader->uint16());
        $properties = PropertySet::read($reader, self::UNSUBSCRIBE_PROPERTIES, 'UNSUBSCRIBE');
        $filters = [];

        while (! $reader->atEnd()) {
            $filters[] = self::receivedTopicFilter($reader->utf8('topic filter'));
        }

        if ($filters === []) {
            throw ProtocolException::protocolError('UNSUBSCRIBE without topic filters.');
        }

        return new Unsubscribe($packetId, $filters, $properties->userPropertyMap());
    }

    private function decodeUnsubAck(Reader $reader): UnsubAck
    {
        $packetId = self::receivedPacketId($reader->uint16());
        $properties = PropertySet::read($reader, self::ACK_PROPERTIES, 'UNSUBACK');

        return new UnsubAck($packetId, self::reasonCodes($reader, 'UNSUBACK'), $properties->string(Property::ReasonString), $properties->userPropertyMap());
    }

    private function decodeDisconnect(Reader $reader, int $length): Disconnect
    {
        // Normal disconnection may be sent with no body, and the property length may be left out (§3.14.2.1).
        if ($length === 0) {
            return new Disconnect;
        }

        $reason = self::reason($reader->byte());

        if ($length === 1) {
            return new Disconnect($reason);
        }

        $properties = PropertySet::read($reader, self::DISCONNECT_PROPERTIES, 'DISCONNECT');
        $reader->assertAtEnd('DISCONNECT');

        return new Disconnect(
            reasonCode: $reason,
            sessionExpiryInterval: $properties->int(Property::SessionExpiryInterval),
            reasonString: $properties->string(Property::ReasonString),
            serverReference: $properties->string(Property::ServerReference),
            userProperties: $properties->userPropertyMap(),
        );
    }

    // -------------------------------------------------------------- helpers

    private static function properties(PropertySet $set): Properties
    {
        if ($set->userProperties === [] && $set->subscriptionIdentifiers === []
            && ! $set->has(Property::PayloadFormatIndicator) && ! $set->has(Property::MessageExpiryInterval)
            && ! $set->has(Property::ContentType) && ! $set->has(Property::ResponseTopic) && ! $set->has(Property::CorrelationData)) {
            return self::$noProperties ??= new Properties;
        }

        try {
            return new Properties(
                contentType: $set->string(Property::ContentType),
                responseTopic: $set->string(Property::ResponseTopic),
                correlationData: $set->string(Property::CorrelationData),
                messageExpiryInterval: $set->int(Property::MessageExpiryInterval),
                payloadFormatIndicator: $set->bool(Property::PayloadFormatIndicator) ?? false,
                userProperties: $set->userPropertyMap(),
                subscriptionIdentifiers: $set->subscriptionIdentifiers,
            );
        } catch (InvalidArgumentException $e) {
            throw new ProtocolException($e->getMessage(), ReasonCode::ProtocolError, $e);
        }
    }

    private static function rejectAuthentication(PropertySet $properties): void
    {
        if ($properties->has(Property::AuthenticationMethod) || $properties->has(Property::AuthenticationData)) {
            throw ProtocolException::protocolError('Enhanced authentication is not supported.');
        }
    }

    /**
     * @return non-empty-list<ReasonCode>
     */
    private static function reasonCodes(Reader $reader, string $packet): array
    {
        $codes = [];

        while (! $reader->atEnd()) {
            $codes[] = self::reason($reader->byte());
        }

        if ($codes === []) {
            throw ProtocolException::protocolError("{$packet} without reason codes.");
        }

        return $codes;
    }

    private static function reason(int $code): ReasonCode
    {
        return ReasonCode::tryFrom($code) ?? throw new ProtocolException(sprintf('Unknown reason code 0x%02X.', $code));
    }

    private static function empty(Reader $reader, string $name, Packet $packet): Packet
    {
        $reader->assertAtEnd($name);

        return $packet;
    }

    /**
     * @return int<0, 2>
     */
    private static function qos(int $qos): int
    {
        if ($qos < 0 || $qos > 2) {
            throw new ProtocolException("Invalid QoS level [{$qos}]; expected 0..2.");
        }

        return $qos;
    }

    private static function flag(?bool $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
    }

    /**
     * @return int<1, 65535>
     */
    private static function packetId(?int $id, string $what): int
    {
        if ($id === null || $id < 1 || $id > 65535) {
            throw new ProtocolException("{$what} needs a packet identifier between 1 and 65535.");
        }

        return $id;
    }

    /**
     * @return int<1, 65535>
     */
    private static function receivedPacketId(int $id): int
    {
        if ($id < 1 || $id > 65535) {
            throw ProtocolException::protocolError('Packet identifier 0 is not allowed.');
        }

        return $id;
    }

    private static function topicName(string $topic, bool $hasAlias): string
    {
        if ($topic === '' && ! $hasAlias) {
            throw new ProtocolException('Topic names cannot be empty.');
        }

        if (strpbrk($topic, '+#') !== false) {
            throw new ProtocolException("Topic name [{$topic}] contains a wildcard.");
        }

        return $topic;
    }

    private static function receivedTopicName(string $topic, bool $hasAlias): string
    {
        try {
            return self::topicName($topic, $hasAlias);
        } catch (ProtocolException $e) {
            throw ProtocolException::protocolError($e->getMessage());
        }
    }

    private static function topicFilter(string $filter): string
    {
        if ($filter === '') {
            throw new ProtocolException('Topic filters cannot be empty.');
        }

        return $filter;
    }

    private static function receivedTopicFilter(string $filter): string
    {
        if ($filter === '') {
            throw ProtocolException::protocolError('Topic filters cannot be empty.');
        }

        return $filter;
    }
}
