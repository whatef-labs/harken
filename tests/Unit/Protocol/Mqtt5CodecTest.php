<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
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

function mqtt5_decode(string $bytes): Packet
{
    $packets = (new Mqtt5Codec)->decoder()->push($bytes);

    expect($packets)->toHaveCount(1);

    return $packets[0];
}

function mqtt5_reason(Closure $fn): ReasonCode
{
    try {
        $fn();
    } catch (ProtocolException $e) {
        return $e->reasonCode;
    }

    throw new RuntimeException('No ProtocolException thrown.');
}

it('speaks MQTT 5', function () {
    expect((new Mqtt5Codec)->version())->toBe(ProtocolVersion::V5_0);
});

it('round-trips every supported packet', function (Packet $packet) {
    $codec = new Mqtt5Codec;

    expect($codec->decoder()->push($codec->encode($packet)))->toEqual([$packet]);
})->with([
    'connect minimal' => fn () => new Connect('harken-1'),
    'connect full' => fn () => new Connect(
        clientId: 'harken-2',
        keepAlive: 30,
        cleanSession: false,
        username: 'user',
        password: "se\x00cret",
        will: new Publish('lwt/harken-2', 'gone', 1, true, properties: new Properties(
            contentType: 'text/plain',
            responseTopic: 'lwt/ack',
            correlationData: "\x01\x02",
            messageExpiryInterval: 600,
            payloadFormatIndicator: true,
            userProperties: ['reason' => 'crash'],
        )),
        sessionExpiryInterval: 3600,
        receiveMaximum: 100,
        maximumPacketSize: 1048576,
        topicAliasMaximum: 10,
        requestResponseInformation: true,
        requestProblemInformation: false,
        userProperties: ['app' => 'harken', 'tag' => ['a', 'b']],
        willDelayInterval: 5,
    ),
    'connect password without user name' => fn () => new Connect('c', password: 'token'),
    'connack minimal' => fn () => new ConnAck,
    'connack full' => fn () => new ConnAck(
        reasonCode: ReasonCode::Success,
        sessionPresent: true,
        sessionExpiryInterval: 120,
        receiveMaximum: 32,
        maximumQos: 1,
        retainAvailable: false,
        maximumPacketSize: 1048576,
        assignedClientIdentifier: 'auto-1',
        topicAliasMaximum: 65535,
        reasonString: 'welcome',
        userProperties: ['node' => 'emqx@a'],
        wildcardSubscriptionAvailable: true,
        subscriptionIdentifiersAvailable: false,
        sharedSubscriptionAvailable: true,
        serverKeepAlive: 30,
        responseInformation: 'resp/',
        serverReference: 'other:1883',
    ),
    'connack refused' => fn () => new ConnAck(ReasonCode::NotAuthorized, reasonString: 'no'),
    'publish qos0' => fn () => new Publish('a/b', 'hello'),
    'publish qos1 retained duplicate' => fn () => new Publish('a/b', 'hello', 1, true, true, 513),
    'publish empty payload' => fn () => new Publish('a/b', '', 1, true, false, 1),
    'publish with properties' => fn () => new Publish('a/b', '{}', 1, false, false, 9, new Properties(
        contentType: 'application/json',
        responseTopic: 'reply/9',
        correlationData: random_bytes(16),
        messageExpiryInterval: 0,
        payloadFormatIndicator: true,
        userProperties: ['k' => 'v', 'dup' => ['1', '2']],
        subscriptionIdentifiers: [1, 268435455],
    )),
    'publish with topic alias' => fn () => new Publish('', 'x', topicAlias: 3),
    'puback' => fn () => new PubAck(65535),
    'puback with reason' => fn () => new PubAck(1, ReasonCode::NoMatchingSubscribers),
    'puback with properties' => fn () => new PubAck(2, ReasonCode::NotAuthorized, 'denied by acl', ['rule' => '7']),
    'subscribe' => fn () => new Subscribe(7, ['a/+' => 1, '$share/g/b/#' => 0]),
    'subscribe with properties' => fn () => new Subscribe(8, ['x' => 2], 42, ['k' => 'v']),
    'suback' => fn () => new SubAck(7, [ReasonCode::GrantedQos1, ReasonCode::Success, ReasonCode::NotAuthorized]),
    'suback with properties' => fn () => new SubAck(7, [ReasonCode::GrantedQos2], 'ok', ['k' => 'v']),
    'unsubscribe' => fn () => new Unsubscribe(8, ['a/+', 'b'], ['k' => 'v']),
    'unsuback' => fn () => new UnsubAck(8, [ReasonCode::Success, ReasonCode::NoSubscriptionExisted], 'done'),
    'pingreq' => fn () => new PingReq,
    'pingresp' => fn () => new PingResp,
    'disconnect' => fn () => new Disconnect,
    'disconnect with reason' => fn () => new Disconnect(ReasonCode::SessionTakenOver),
    'disconnect with properties' => fn () => new Disconnect(ReasonCode::UseAnotherServer, 0, 'moving', 'other:1883', ['k' => 'v']),
]);

it('encodes packets byte for byte as the specification lays them out', function (Packet $packet, string $hex) {
    expect(bin2hex((new Mqtt5Codec)->encode($packet)))->toBe(str_replace(' ', '', $hex))
        ->and(mqtt5_decode((string) hex2bin(str_replace(' ', '', $hex))))->toEqual($packet);
})->with([
    // Protocol name "MQTT", level 5, clean start, keep alive 60, no properties, client id "a" (§3.1).
    'connect' => [new Connect('a'), '10 0e 0004 4d515454 05 02 003c 00 0001 61'],
    // Topic "a/b", packet id 10, content type "text/plain", payload "hi" (§3.3).
    'publish' => [
        new Publish('a/b', 'hi', 1, false, false, 10, new Properties(contentType: 'text/plain')),
        '32 17 0003 612f62 000a 0d 03 000a 746578742f706c61696e 6869',
    ],
    // Success without properties may omit reason code and property length (§3.4.2.1).
    'puback success' => [new PubAck(10), '40 02 000a'],
    'puback reason only' => [new PubAck(10, ReasonCode::NoMatchingSubscribers), '40 03 000a 10'],
    'subscribe' => [new Subscribe(1, ['a/#' => 1]), '82 09 0001 00 0003 612f23 01'],
    'suback' => [new SubAck(1, [ReasonCode::GrantedQos1]), '90 04 0001 00 01'],
    'connack' => [new ConnAck(sessionPresent: true, receiveMaximum: 32), '20 06 01 00 03 21 0020'],
    'disconnect normal' => [new Disconnect, 'e0 00'],
    'disconnect reason only' => [new Disconnect(ReasonCode::SessionTakenOver), 'e0 01 8e'],
    'pingreq' => [new PingReq, 'c0 00'],
]);

it('reads a property block longer than 127 bytes', function () {
    $properties = new Properties(userProperties: ['trace' => str_repeat('t', 100), 'span' => str_repeat('s', 100)]);
    $packet = new Publish('a', 'payload', 1, false, false, 1, $properties);
    $bytes = (new Mqtt5Codec)->encode($packet);

    // After the fixed header (2 + 1 bytes for 230+ bytes), topic (3) and packet id (2),
    // the property length is a two-byte variable byte integer.
    expect(ord($bytes[8]) & 0x80)->toBe(0x80)
        ->and(mqtt5_decode($bytes))->toEqual($packet)
        ->and(mqtt5_decode($bytes)->payload)->toBe('payload');
});

it('keeps repeated user property names and their order', function () {
    $packet = new Publish('a', 'b', properties: new Properties(userProperties: ['x' => ['1', '2', '3'], 'y' => '4']));
    $decoded = mqtt5_decode((new Mqtt5Codec)->encode($packet));

    expect($decoded->properties->userProperties)->toBe(['x' => ['1', '2', '3'], 'y' => '4'])
        ->and($decoded->properties->userProperty('x'))->toBe('1');
});

it('keeps binary payloads byte for byte', function () {
    $codec = new Mqtt5Codec;
    $payload = random_bytes(70000)."\x00\xff\xfe";

    expect(mqtt5_decode($codec->encode(new Publish('bin', $payload, 1, false, false, 1)))->payload)->toBe($payload);
});

it('reassembles packets split across reads', function () {
    $codec = new Mqtt5Codec;
    $bytes = $codec->encode(new Publish('a/b', str_repeat('x', 300), 1, false, false, 9, new Properties(contentType: 'c')))
        .$codec->encode(new PingResp)
        .$codec->encode(new PubAck(9, ReasonCode::NoMatchingSubscribers));
    $decoder = $codec->decoder();
    $packets = [];

    foreach (str_split($bytes, 3) as $chunk) {
        array_push($packets, ...$decoder->push($chunk));
    }

    expect($packets)->toHaveCount(3)
        ->and($packets[0])->toBeInstanceOf(Publish::class)
        ->and($packets[1])->toBeInstanceOf(PingResp::class)
        ->and($packets[2])->toEqual(new PubAck(9, ReasonCode::NoMatchingSubscribers));
});

it('reads a 3.1.1-format CONNACK from a broker without MQTT 5 as a refusal', function () {
    expect(mqtt5_decode("\x20\x02\x00\x01"))->toEqual(new ConnAck(ReasonCode::UnsupportedProtocolVersion))
        ->and(mqtt5_decode("\x20\x02\x00\x84"))->toEqual(new ConnAck(ReasonCode::UnsupportedProtocolVersion));
});

it('rejects malformed packets', function (string $hex) {
    expect(mqtt5_reason(fn () => mqtt5_decode((string) hex2bin(str_replace(' ', '', $hex)))))->toBe(ReasonCode::MalformedPacket);
})->with([
    'unknown property' => '30 06 0001 61 01 05 00',
    'property length past the end' => '30 04 0001 61 09',
    'string past the end' => '30 03 0005 61',
    'invalid utf-8 topic' => '30 05 0002 c328 00',
    'qos 3' => '36 06 0001 61 0001 00',
    'suback with wrong flags' => '91 04 0001 00 01',
    'pingresp with a body' => 'd0 01 00',
    'unknown reason code' => '40 03 0001 03',
    'trailing bytes after puback properties' => '40 05 0001 00 00 ff',
    'connack reserved flags' => '20 03 02 00 00',
    'connack without properties claiming success' => '20 02 00 00',
    'varint longer than four bytes' => '30 08 0001 61 ff ff ff ff 01',
]);

it('rejects packets that break protocol rules', function (string $hex) {
    expect(mqtt5_reason(fn () => mqtt5_decode((string) hex2bin(str_replace(' ', '', $hex)))))->toBe(ReasonCode::ProtocolError);
})->with([
    'packet identifier 0' => '40 02 0000',
    'property not allowed in packet' => '30 07 0001 61 03 21 0001',
    'property repeated' => '30 0c 0001 61 08 03 0001 61 03 0001 62',
    'flag property out of range' => '30 06 0001 61 02 01 02',
    'topic alias 0' => '30 07 0001 61 03 23 0000',
    'wildcard in received topic' => '30 04 0001 2b 00',
    'empty topic without alias' => '30 03 0000 00',
    'suback without codes' => '90 03 0001 00',
    'auth packet' => 'f0 00',
    'qos 2 flow' => '50 02 0001',
    'connack with authentication method' => '20 07 00 00 04 15 0001 78',
]);

it('refuses to encode invalid packets', function (Closure $encode) {
    $encode(new Mqtt5Codec);
})->throws(ProtocolException::class)->with([
    'qos 1 without packet id' => [fn (Mqtt5Codec $c) => $c->encode(new Publish('a', 'b', 1))],
    'wildcard topic' => [fn (Mqtt5Codec $c) => $c->encode(new Publish('a/+', 'b'))],
    'empty topic without alias' => [fn (Mqtt5Codec $c) => $c->encode(new Publish('', 'b'))],
    'dup on qos 0' => [fn (Mqtt5Codec $c) => $c->encode(new Publish('a', 'b', duplicate: true))],
    'receive maximum 0' => [fn (Mqtt5Codec $c) => $c->encode(new Connect('c', receiveMaximum: 0))],
    'keep alive above 16 bits' => [fn (Mqtt5Codec $c) => $c->encode(new Connect('c', keepAlive: 65536))],
    'will with subscription identifier' => [fn (Mqtt5Codec $c) => $c->encode(new Connect('c', will: new Publish('w', 'x', properties: new Properties(subscriptionIdentifiers: [1]))))],
    'unsuback without codes' => [fn (Mqtt5Codec $c) => $c->encode(new UnsubAck(1))],
    'maximum qos 2 in connack' => [fn (Mqtt5Codec $c) => $c->encode(new ConnAck(maximumQos: 2))],
    'invalid utf-8 client id' => [fn (Mqtt5Codec $c) => $c->encode(new Connect("\xff"))],
]);

it('reports any byte sequence as packets or a protocol error, never a PHP error', function () {
    mt_srand(5);
    $codec = new Mqtt5Codec;
    $samples = [
        $codec->encode(new Connect('c', 10, false, 'u', 'p', new Publish('w', 'x', 1, true, properties: new Properties(contentType: 't')), 60, 10)),
        $codec->encode(new ConnAck(sessionPresent: true, receiveMaximum: 5, reasonString: 'r', userProperties: ['a' => 'b'])),
        $codec->encode(new Publish('a/b', 'payload', 1, false, false, 7, new Properties(contentType: 'c', correlationData: 'd', userProperties: ['k' => 'v'], subscriptionIdentifiers: [9]))),
        $codec->encode(new PubAck(7, ReasonCode::NotAuthorized, 'no')),
        $codec->encode(new SubAck(3, [ReasonCode::GrantedQos1])),
        $codec->encode(new Subscribe(3, ['a/#' => 1], 4)),
        $codec->encode(new Disconnect(ReasonCode::ServerMoved, 1, 'r', 'ref')),
    ];
    $outcomes = ['packets' => 0, 'errors' => 0];

    for ($i = 0; $i < 3000; $i++) {
        $bytes = $samples[$i % count($samples)];

        if ($i % 3 === 0) {
            $bytes = random_bytes(mt_rand(1, 64));
        } else {
            for ($flips = mt_rand(1, 3); $flips > 0; $flips--) {
                $at = mt_rand(0, strlen($bytes) - 1);
                $bytes[$at] = chr(ord($bytes[$at]) ^ (1 << mt_rand(0, 7)));
            }
        }

        try {
            $codec->decoder()->push($bytes);
            $outcomes['packets']++;
        } catch (ProtocolException) {
            $outcomes['errors']++;
        }
    }

    expect($outcomes['packets'] + $outcomes['errors'])->toBe(3000)
        ->and($outcomes['errors'])->toBeGreaterThan(0);
});

it('applies the maximum packet size', function () {
    $codec = new Mqtt5Codec;

    expect(mqtt5_reason(fn () => $codec->decoder(64)->push($codec->encode(new Publish('a', str_repeat('x', 100))))))
        ->toBe(ReasonCode::PacketTooLarge);
});
