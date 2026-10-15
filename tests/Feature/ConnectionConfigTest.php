<?php

declare(strict_types=1);

use Whatef\Harken\Laravel\ConnectionConfig;
use Whatef\Harken\Observability\LogContext;

it('builds options from the default config block', function () {
    $options = ConnectionConfig::options('default', config('harken.connections.default'));

    expect($options->endpoint->uri())->toBe('tcp://127.0.0.1:1883')
        ->and($options->endpoint->tls)->toBeNull()
        ->and($options->clientId)->toStartWith('harken-')
        ->and($options->keepAlive)->toBe(60)
        ->and($options->cleanSession)->toBeTrue()
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

it('rejects unsupported protocols', function () {
    ConnectionConfig::options('default', ['host' => 'h', 'protocol' => '5.0']);
})->throws(InvalidArgumentException::class, 'not supported yet');
