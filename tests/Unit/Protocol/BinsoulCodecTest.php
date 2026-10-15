<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Disconnect;
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

it('speaks MQTT 3.1.1', function () {
    expect((new BinsoulCodec)->version())->toBe(ProtocolVersion::V3_1_1);
});

it('round-trips every supported packet', function (object $packet) {
    $codec = new BinsoulCodec;

    expect($codec->decoder()->push($codec->encode($packet)))->toEqual([$packet]);
})->with([
    'connect' => fn () => new Connect('harken-1', 30, false, 'user', 'secret', new Publish('lwt/harken-1', 'gone', 1, true)),
    'connect minimal' => fn () => new Connect('harken-2'),
    'connack' => fn () => new ConnAck(0, true),
    'publish qos0' => fn () => new Publish('a/b', 'hello'),
    'publish qos1 retained duplicate' => fn () => new Publish('a/b', 'hello', 1, true, true, 513),
    'publish empty retained' => fn () => new Publish('a/b', '', 1, true, false, 1),
    'puback' => fn () => new PubAck(65535),
    'subscribe' => fn () => new Subscribe(7, ['a/+' => 1, '$share/g/b/#' => 0]),
    'suback' => fn () => new SubAck(7, [1, 0x80]),
    'unsubscribe' => fn () => new Unsubscribe(8, ['a/+']),
    'unsuback' => fn () => new UnsubAck(8),
    'pingreq' => fn () => new PingReq,
    'pingresp' => fn () => new PingResp,
    'disconnect' => fn () => new Disconnect,
]);

it('keeps binary payloads byte for byte', function () {
    $codec = new BinsoulCodec;
    $payload = random_bytes(4096)."\x00\xff\xfe";

    [$decoded] = $codec->decoder()->push($codec->encode(new Publish('bin', $payload, 1, false, false, 1)));

    expect($decoded->payload)->toBe($payload);
});

it('reassembles packets split across reads', function () {
    $codec = new BinsoulCodec;
    $bytes = $codec->encode(new Publish('a/b', str_repeat('x', 300), 1, false, false, 9))
        .$codec->encode(new PingResp)
        .$codec->encode(new PubAck(9));
    $decoder = $codec->decoder();
    $packets = [];

    foreach (str_split($bytes, 7) as $chunk) {
        array_push($packets, ...$decoder->push($chunk));
    }

    expect($packets)->toHaveCount(3)
        ->and($packets[0])->toBeInstanceOf(Publish::class)
        ->and($packets[1])->toBeInstanceOf(PingResp::class)
        ->and($packets[2])->toEqual(new PubAck(9));
});

it('requires a packet identifier for QoS 1 publishes', function () {
    (new BinsoulCodec)->encode(new Publish('a', 'b', 1));
})->throws(ProtocolException::class, 'packet identifier');

it('rejects wildcard topics in PUBLISH', function () {
    (new BinsoulCodec)->encode(new Publish('a/+', 'b'));
})->throws(ProtocolException::class);

it('reports malformed input as a protocol error', function () {
    // CONNACK with remaining length 3 instead of 2.
    (new BinsoulCodec)->decoder()->push("\x20\x03\x00\x00\x00");
})->throws(ProtocolException::class);

it('hides the password when dumped', function () {
    expect(print_r(new Connect('id', password: 'hunter2'), true))->not->toContain('hunter2');
});
