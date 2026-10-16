<?php

declare(strict_types=1);

use Whatef\Harken\Laravel\ConnectionConfig;
use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\ProtocolVersion;

it('builds options from the default config block', function () {
    $options = ConnectionConfig::options('default', config('harken.connections.default'));

    expect($options->endpoint->uri())->toBe('tcp://127.0.0.1:1883')
        ->and($options->endpoint->tls)->toBeNull()
        ->and($options->clientId)->toStartWith('harken-')
        ->and($options->keepAlive)->toBe(60)
        ->and($options->cleanSession)->toBeTrue()
        ->and($options->protocol)->toBe(ProtocolVersion::V5_0)
        ->and($options->username)->toBeNull();
});

it('generates a different client id per process start', function () {
    $config = config('harken.connections.default');

    expect(ConnectionConfig::options('default', $config)->clientId)
        ->not->toBe(ConnectionConfig::options('default', $config)->clientId);
});

it('keeps an explicit client id', function () {
    $options = ConnectionConfig::options('default', ['host' => 'h', 'client_id' => 'fixed-1']);

    expect($options->clientId)->toBe('fixed-1');
});

it('enables TLS and credentials', function () {
    $options = ConnectionConfig::options('secure', [
        'host' => 'broker.internal',
        'port' => '8883',
        'username' => 'svc',
        'password' => 'hunter2',
        'tls' => ['enabled' => true, 'cafile' => '/certs/ca.crt'],
    ]);

    expect($options->endpoint->uri())->toBe('tls://broker.internal:8883')
        ->and($options->endpoint->tls?->caFile)->toBe('/certs/ca.crt')
        ->and($options->password)->toBe('hunter2')
        ->and(print_r($options, true))->not->toContain('hunter2')
        ->and(json_encode(LogContext::redact(config('harken.connections.default'))))->not->toContain('hunter2');
});

it('selects the codec from the protocol', function (string $protocol, string $codec) {
    $options = ConnectionConfig::options('default', ['host' => 'h', 'protocol' => $protocol]);

    expect($options->protocol->value)->toBe($protocol)
        ->and($options->protocol->codec())->toBeInstanceOf($codec);
})->with([
    ['5.0', Mqtt5Codec::class],
    ['3.1.1', BinsoulCodec::class],
]);

it('rejects unsupported protocols', function (mixed $protocol) {
    ConnectionConfig::options('default', ['host' => 'h', 'protocol' => $protocol]);
})->with(['3.1', '5', 5.0])->throws(InvalidArgumentException::class, 'use "5.0" or "3.1.1"');
