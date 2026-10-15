<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\ClientId;

it('combines prefix, hostname, pid and a random suffix', function () {
    expect(ClientId::generate('harken', 'web-1', 42, 'a1b2c3'))->toBe('harken-web-1-42-a1b2c3');
});

it('removes characters brokers may reject', function () {
    expect(ClientId::generate('my app!', 'host.example.com', 7, 'ff'))->toBe('myapp-hostexamplecom-7-ff');
});

it('differs between replicas with the same hostname and pid', function () {
    $ids = array_map(fn () => ClientId::generate('harken', 'same', 1), range(1, 50));

    expect(array_unique($ids))->toHaveCount(50);
});

it('uses the real hostname and pid by default', function () {
    expect(ClientId::generate('harken'))->toMatch('/^harken-[A-Za-z0-9_-]+-'.getmypid().'-[0-9a-f]{6}$/');
});

it('requires a usable prefix', function () {
    ClientId::generate('!!!');
})->throws(InvalidArgumentException::class);
