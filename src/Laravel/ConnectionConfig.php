<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use InvalidArgumentException;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\ClientId;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\TlsOptions;

/**
 * Turns one `harken.connections.*` block into runtime options.
 */
final class ConnectionConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function options(string $name, array $config): ConnectionOptions
    {
        $host = $config['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Harken connection [{$name}] needs a host.");
        }

        $protocol = $config['protocol'] ?? ProtocolVersion::V5_0->value;
        $version = is_string($protocol) ? ProtocolVersion::tryFrom($protocol) : null;

        if ($version === null) {
            throw new InvalidArgumentException(sprintf(
                'Harken connection [%s]: protocol [%s] is not supported; use "5.0" or "3.1.1".',
                $name,
                is_scalar($protocol) ? $protocol : get_debug_type($protocol),
            ));
        }

        $tls = is_array($config['tls'] ?? null) ? $config['tls'] : [];
        /** @var array<string, mixed> $tls */
        $reconnect = is_array($config['reconnect'] ?? null) ? $config['reconnect'] : [];
        /** @var array<string, mixed> $reconnect */
        $clientId = $config['client_id'] ?? null;
        $prefix = $config['client_id_prefix'] ?? 'harken';

        return new ConnectionOptions(
            name: $name,
            endpoint: new Endpoint(
                host: $host,
                port: self::int($config, 'port', 1883),
                tls: ($tls['enabled'] ?? false) ? TlsOptions::fromArray($tls) : null,
                connectTimeout: is_numeric($config['connect_timeout'] ?? null) ? (float) $config['connect_timeout'] : 5.0,
            ),
            clientId: is_string($clientId) && $clientId !== ''
                ? $clientId
                : ClientId::generate(is_string($prefix) ? $prefix : 'harken'),
            backoff: ExponentialBackoff::fromArray($reconnect),
            keepAlive: self::int($config, 'keepalive', 60),
            cleanSession: (bool) ($config['clean_session'] ?? true),
            username: self::nullableString($config, 'username'),
            password: self::nullableString($config, 'password'),
            protocol: $version,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        return is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
