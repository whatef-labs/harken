<?php

declare(strict_types=1);

use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\TlsOptions;

it('builds a TLS 1.2+ stream context from config', function () {
    $tls = TlsOptions::fromArray([
        'cafile' => '/certs/ca.crt',
        'local_cert' => '/certs/client.crt',
        'local_pk' => '/certs/client.key',
        'passphrase' => null,
        'verify_peer' => true,
        'peer_name' => 'broker.internal',
    ]);

    expect($tls->toStreamContext())->toBe([
        'cafile' => '/certs/ca.crt',
        'local_cert' => '/certs/client.crt',
        'local_pk' => '/certs/client.key',
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => 'broker.internal',
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
    ]);
});

it('selects the tls scheme only when TLS is configured', function () {
    expect((new Endpoint('h', 1883))->uri())->toBe('tcp://h:1883')
        ->and((new Endpoint('h', 8883, new TlsOptions))->uri())->toBe('tls://h:8883');
});

it('hides the key passphrase when dumped', function () {
    expect(print_r(new TlsOptions(passphrase: 'hunter2'), true))->not->toContain('hunter2');
});

it('knows when the endpoint host needs no DNS lookup', function (string $host, bool $ip) {
    expect((new Endpoint($host))->hostIsIp())->toBe($ip);
})->with([
    ['127.0.0.1', true],
    ['::1', true],
    ['[::1]', true],
    ['broker.lan', false],
    ['localhost', false],
]);
