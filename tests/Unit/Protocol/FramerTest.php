<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Protocol\Wire\Frame;
use Whatef\Harken\Protocol\Wire\Framer;

function frame_bytes(int $first, string $body): string
{
    $length = strlen($body);
    $header = chr($first);

    do {
        $digit = $length % 128;
        $length = intdiv($length, 128);
        $header .= chr($length > 0 ? $digit | 0x80 : $digit);
    } while ($length > 0);

    return $header.$body;
}

it('cuts packets at every remaining-length boundary', function (int $size, int $headerLength) {
    $body = str_repeat('b', $size);
    $bytes = frame_bytes(0x32, $body);

    [$frame] = (new Framer)->push($bytes);

    expect($frame)->toBeInstanceOf(Frame::class)
        ->and($frame->type)->toBe(3)
        ->and($frame->flags)->toBe(2)
        ->and(strlen($frame->header))->toBe($headerLength)
        ->and($frame->body)->toBe($body)
        ->and($frame->bytes())->toBe($bytes);
})->with([
    [0, 2],
    [127, 2],
    [128, 3],
    [16383, 3],
    [16384, 4],
    [2097151, 4],
    [2097152, 5],
]);

it('reassembles packets fed one byte at a time', function () {
    $bytes = frame_bytes(0x30, str_repeat('x', 300)).frame_bytes(0xD0, '').frame_bytes(0x40, "\x00\x01");
    $framer = new Framer;
    $frames = [];

    foreach (str_split($bytes) as $byte) {
        array_push($frames, ...$framer->push($byte));
    }

    expect(array_map(fn (Frame $f) => $f->type, $frames))->toBe([3, 13, 4])
        ->and(implode('', array_map(fn (Frame $f) => $f->bytes(), $frames)))->toBe($bytes);
});

it('rejects a remaining length longer than four bytes', function () {
    (new Framer)->push("\x30\xff\xff\xff\xff\x01");
})->throws(ProtocolException::class, 'longer than four bytes');

it('rejects the reserved packet type 0', function () {
    (new Framer)->push("\x00\x00");
})->throws(ProtocolException::class, 'reserved');

it('rejects an oversized packet from its header alone', function () {
    try {
        (new Framer(1024))->push("\x30\x80\x08"); // announces 1024 bytes of body
    } catch (ProtocolException $e) {
        expect($e->reasonCode)->toBe(ReasonCode::PacketTooLarge);

        return;
    }

    test()->fail('No exception thrown.');
});

it('accepts a packet of exactly the maximum size', function () {
    $bytes = frame_bytes(0x30, str_repeat('x', 1021));

    expect(strlen($bytes))->toBe(1024)
        ->and((new Framer(1024))->push($bytes))->toHaveCount(1);
});

it('applies the limit in the 3.1.1 decoder', function () {
    $codec = new BinsoulCodec;

    $codec->decoder(64)->push($codec->encode(new Publish('a', str_repeat('x', 100))));
})->throws(ProtocolException::class, 'maximum packet size');
