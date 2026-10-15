<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\PacketIdPool;
use Whatef\Harken\Protocol\ProtocolException;

it('hands out sequential identifiers starting at 1', function () {
    $pool = new PacketIdPool;

    expect([$pool->acquire(), $pool->acquire(), $pool->acquire()])->toBe([1, 2, 3])
        ->and($pool->inUse())->toBe(3);
});

it('wraps around and skips identifiers still in flight', function () {
    $pool = new PacketIdPool;

    for ($i = 1; $i <= 65534; $i++) {
        $pool->acquire();
    }
    $pool->release(2);

    expect($pool->acquire())->toBe(65535)
        ->and($pool->acquire())->toBe(2);
});

it('fails when every identifier is in flight', function () {
    $pool = new PacketIdPool;

    for ($i = 1; $i <= 65535; $i++) {
        $pool->acquire();
    }

    $pool->acquire();
})->throws(ProtocolException::class);
