<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Binsoul;

use BinSoul\Net\Mqtt\Packet as BinsoulPacket;
use BinSoul\Net\Mqtt\Packet\ConnectRequestPacket;
use BinSoul\Net\Mqtt\Packet\ConnectResponsePacket;
use BinSoul\Net\Mqtt\Packet\DisconnectRequestPacket;
use BinSoul\Net\Mqtt\Packet\PingRequestPacket;
use BinSoul\Net\Mqtt\Packet\PingResponsePacket;
use BinSoul\Net\Mqtt\Packet\PublishAckPacket;
use BinSoul\Net\Mqtt\Packet\PublishRequestPacket;
use BinSoul\Net\Mqtt\Packet\SubscribeRequestPacket;
use BinSoul\Net\Mqtt\Packet\SubscribeResponsePacket;
use BinSoul\Net\Mqtt\Packet\UnsubscribeRequestPacket;
use BinSoul\Net\Mqtt\Packet\UnsubscribeResponsePacket;
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
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\ProtocolVersion;

/**
 * MQTT 3.1.1 codec backed by binsoul/net-mqtt packet classes.
 *
 * Only the packet classes are used. binsoul's Flow objects are not: they emit
 * an inbound QoS 1 message and build its PUBACK in the same step, while the
 * runtime needs to decide when the PUBACK is sent.
 *
 * Both directions are mapped so the same codec can drive an in-memory broker
 * double in tests. QoS 2 packets are not mapped yet.
 */
final class BinsoulCodec implements Codec
{
    public function version(): ProtocolVersion
    {
        return ProtocolVersion::V3_1_1;
    }

    public function encode(Packet $packet): string
    {
        try {
            return (string) $this->toBinsoul($packet);
        } catch (InvalidArgumentException $e) {
            throw new ProtocolException($e->getMessage(), 0, $e);
        }
    }

    public function decoder(): Decoder
    {
        return new BinsoulDecoder($this);
    }

    /**
     * @internal Used by BinsoulDecoder.
     */
    public function fromBinsoul(BinsoulPacket $packet): Packet
    {
        return match (true) {
            $packet instanceof PublishRequestPacket => new Publish(
                topic: $packet->getTopic(),
                payload: $packet->getPayload(),
                qos: $packet->getQosLevel(),
                retain: $packet->isRetained(),
                duplicate: $packet->isDuplicate(),
                packetId: $packet->getIdentifier(),
            ),
            $packet instanceof PublishAckPacket => new PubAck($this->identifier($packet->getIdentifier())),
            $packet instanceof ConnectResponsePacket => new ConnAck($packet->getReturnCode(), $packet->isSessionPresent()),
            $packet instanceof SubscribeResponsePacket => new SubAck(
                $this->identifier($packet->getIdentifier()),
                array_values($packet->getReturnCodes()),
            ),
            $packet instanceof UnsubscribeResponsePacket => new UnsubAck($this->identifier($packet->getIdentifier())),
            $packet instanceof PingResponsePacket => new PingResp,
            $packet instanceof PingRequestPacket => new PingReq,
            $packet instanceof DisconnectRequestPacket => new Disconnect,
            $packet instanceof ConnectRequestPacket => new Connect(
                clientId: $packet->getClientID(),
                keepAlive: $packet->getKeepAlive(),
                cleanSession: $packet->isCleanSession(),
                username: $packet->hasUsername() ? $packet->getUsername() : null,
                password: $packet->hasPassword() ? $packet->getPassword() : null,
                will: $packet->hasWill()
                    ? new Publish($packet->getWillTopic(), $packet->getWillMessage(), $packet->getWillQosLevel(), $packet->isWillRetained())
                    : null,
            ),
            $packet instanceof SubscribeRequestPacket => new Subscribe(
                $this->identifier($packet->getIdentifier()),
                $this->filters($packet->getFilters(), $packet->getQosLevels()),
            ),
            $packet instanceof UnsubscribeRequestPacket => new Unsubscribe(
                $this->identifier($packet->getIdentifier()),
                $this->nonEmptyList($packet->getFilters()),
            ),
            default => throw new ProtocolException(sprintf('Unsupported packet type %d.', $packet->getPacketType())),
        };
    }

    private function toBinsoul(Packet $packet): BinsoulPacket
    {
        switch (true) {
            case $packet instanceof Publish:
                $out = new PublishRequestPacket;
                $out->setTopic(self::topic($packet->topic));
                $out->setPayload($packet->payload);
                $out->setQosLevel(self::qos($packet->qos));
                $out->setRetained($packet->retain);
                $out->setDuplicate($packet->duplicate);
                if ($packet->qos > 0) {
                    if ($packet->packetId === null) {
                        throw new ProtocolException('A QoS 1 or 2 PUBLISH needs a packet identifier.');
                    }
                    $out->setIdentifier($packet->packetId);
                }

                return $out;

            case $packet instanceof PubAck:
                $out = new PublishAckPacket;
                $out->setIdentifier($packet->packetId);

                return $out;

            case $packet instanceof Connect:
                $out = new ConnectRequestPacket;
                $out->setProtocolLevel(4);
                $out->setClientID($packet->clientId);
                $out->setKeepAlive(self::keepAlive($packet->keepAlive));
                $out->setCleanSession($packet->cleanSession);
                if ($packet->username !== null) {
                    $out->setUsername($packet->username);
                }
                if ($packet->password !== null) {
                    $out->setPassword($packet->password);
                }
                if ($packet->will !== null) {
                    $out->setWill(self::topic($packet->will->topic), $packet->will->payload, self::qos($packet->will->qos), $packet->will->retain);
                }

                return $out;

            case $packet instanceof Subscribe:
                $out = new SubscribeRequestPacket;
                $out->setIdentifier($packet->packetId);
                $out->setFilters(array_map(self::topic(...), array_map(strval(...), array_keys($packet->filters))));
                $out->setQosLevels(array_map(self::qos(...), array_values($packet->filters)));

                return $out;

            case $packet instanceof Unsubscribe:
                $out = new UnsubscribeRequestPacket;
                $out->setIdentifier($packet->packetId);
                $out->setFilters(array_map(self::topic(...), $packet->filters));

                return $out;

            case $packet instanceof PingReq:
                return new PingRequestPacket;

            case $packet instanceof Disconnect:
                return new DisconnectRequestPacket;

            case $packet instanceof ConnAck:
                $out = new ConnectResponsePacket;
                $out->setReturnCode(self::connAckCode($packet->returnCode));
                $out->setSessionPresent($packet->sessionPresent);

                return $out;

            case $packet instanceof SubAck:
                $out = new SubscribeResponsePacket;
                $out->setIdentifier($packet->packetId);
                $out->setReturnCodes(array_map(self::subAckCode(...), $packet->returnCodes));

                return $out;

            case $packet instanceof UnsubAck:
                $out = new UnsubscribeResponsePacket;
                $out->setIdentifier($packet->packetId);

                return $out;

            case $packet instanceof PingResp:
                return new PingResponsePacket;
        }

        throw new ProtocolException(sprintf('Unsupported packet %s.', $packet::class));
    }

    /**
     * @return non-empty-string
     */
    private static function topic(string $topic): string
    {
        if ($topic === '') {
            throw new ProtocolException('Topics and topic filters cannot be empty.');
        }

        return $topic;
    }

    /**
     * @return int<0, 2>
     */
    private static function qos(int $qos): int
    {
        if ($qos < 0 || $qos > 2) {
            throw self::outOfRange('QoS level', $qos, 0, 2);
        }

        return $qos;
    }

    /**
     * @return int<0, 65535>
     */
    private static function keepAlive(int $seconds): int
    {
        if ($seconds < 0 || $seconds > 65535) {
            throw self::outOfRange('keep alive', $seconds, 0, 65535);
        }

        return $seconds;
    }

    /**
     * @return int<0, 255>
     */
    private static function connAckCode(int $code): int
    {
        if ($code < 0 || $code > 255) {
            throw self::outOfRange('CONNACK return code', $code, 0, 255);
        }

        return $code;
    }

    /**
     * @return int<0, 128>
     */
    private static function subAckCode(int $code): int
    {
        if ($code < 0 || $code > 128) {
            throw self::outOfRange('SUBACK return code', $code, 0, 128);
        }

        return $code;
    }

    private static function outOfRange(string $what, int $value, int $min, int $max): ProtocolException
    {
        return new ProtocolException("Invalid {$what} [{$value}]; expected {$min}..{$max}.");
    }

    /**
     * @return int<1, 65535>
     */
    private function identifier(?int $id): int
    {
        if ($id === null || $id < 1 || $id > 65535) {
            throw new ProtocolException('Missing or invalid packet identifier.');
        }

        return $id;
    }

    /**
     * @param  array<int, string>  $filters
     * @param  array<int, int>  $qos
     * @return non-empty-array<string, int>
     */
    private function filters(array $filters, array $qos): array
    {
        $map = [];
        foreach (array_values($filters) as $i => $filter) {
            $map[$filter] = array_values($qos)[$i] ?? 0;
        }

        if ($map === []) {
            throw new ProtocolException('SUBSCRIBE without topic filters.');
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $values
     * @return non-empty-list<string>
     */
    private function nonEmptyList(array $values): array
    {
        $values = array_values($values);

        if ($values === []) {
            throw new ProtocolException('UNSUBSCRIBE without topic filters.');
        }

        return $values;
    }
}
