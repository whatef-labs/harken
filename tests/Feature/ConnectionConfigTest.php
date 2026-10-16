<?php

declare(strict_types=1);

use Whatef\Harken\Laravel\ConnectionConfig;
use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\ConnectionClosed;
use Whatef\Harken\Runtime\Connection\ConnectionRefused;

it('builds options from the default config block', function () {
    $options = ConnectionConfig::options('default', config('harken.connections.default'));

    expect($options->endpoint->uri())->toBe('tcp://127.0.0.1:1883')
        ->and($options->endpoint->tls)->toBeNull()
        ->and($options->clientId)->toStartWith('laravel-harken-')
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

it('reads the MQTT 5 session expiry and the nameserver', function () {
    $options = ConnectionConfig::options('default', ['host' => 'broker.lan', 'session_expiry' => '300', 'dns' => '10.0.0.53']);

    expect($options->sessionExpiry)->toBe(300)
        ->and($options->endpoint->nameserver)->toBe('10.0.0.53')
        ->and(ConnectionConfig::options('default', config('harken.connections.default'))->sessionExpiry)->toBe(0);
});

it('names the protocol setting when a broker refuses MQTT 5', function () {
    $options = ConnectionConfig::options('edge', ['host' => 'h']);

    expect(ConnectionConfig::hint(new ConnectionRefused(ReasonCode::UnsupportedProtocolVersion, ProtocolVersion::V5_0), $options))
        ->toBe('The broker does not accept MQTT 5. Set HARKEN_PROTOCOL=3.1.1 (harken.connections.edge.protocol) to connect with MQTT 3.1.1.')
        ->and(ConnectionConfig::hint(ConnectionClosed::beforeConnAck(), $options))
        ->toBe('If the broker does not support MQTT 5, set HARKEN_PROTOCOL=3.1.1 (harken.connections.edge.protocol).')
        ->and(ConnectionConfig::hint(new ConnectionRefused(ReasonCode::NotAuthorized, ProtocolVersion::V5_0), $options))->toBeNull()
        ->and(ConnectionConfig::hint(ConnectionClosed::beforeConnAck(), ConnectionConfig::options('edge', ['host' => 'h', 'protocol' => '3.1.1'])))->toBeNull();
});
