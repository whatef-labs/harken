<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use Whatef\Harken\Runtime\Redis\RedisEndpoint;

/**
 * Reads the application's Redis and PostgreSQL connections from
 * config/database.php for the runtime's own asynchronous connections.
 */
final readonly class StoreConfig
{
    public function __construct(private Config $config) {}

    /**
     * A connection under `database.redis`, with the key prefix the
     * application's Redis client applies to it.
     */
    public function redis(string $name): RedisEndpoint
    {
        $connection = $this->config->get("database.redis.{$name}");

        if (! is_array($connection)) {
            throw new InvalidArgumentException("Redis connection [{$name}] is not configured in config/database.php.");
        }

        /** @var array<string, mixed> $parsed */
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($connection);
        $host = self::string($parsed, 'host') ?? '127.0.0.1';
        $scheme = strtolower(self::string($parsed, 'scheme') ?? self::string($parsed, 'driver') ?? 'tcp');

        if (preg_match('#^(tcp|tls)://(.+)$#i', $host, $m) === 1) {
            $scheme = strtolower($m[1]);
            $host = $m[2];
        }

        if (isset($parsed['cluster']) || isset($parsed['sentinel'])) {
            throw new InvalidArgumentException("Redis connection [{$name}]: clusters and sentinels are not supported by Harken's runtime connections.");
        }

        $global = $this->config->get('database.redis.options.prefix');
        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $prefix = $connection['prefix'] ?? $options['prefix'] ?? $global;

        return new RedisEndpoint(
            host: $host,
            port: is_numeric($parsed['port'] ?? null) ? (int) $parsed['port'] : 6379,
            username: self::string($parsed, 'username'),
            password: self::string($parsed, 'password'),
            database: is_numeric($parsed['database'] ?? null) ? (int) $parsed['database'] : 0,
            tls: $scheme === 'tls' || $scheme === 'rediss',
            prefix: is_string($prefix) ? $prefix : '',
        );
    }

    /**
     * A libpq connection string for a connection under
     * `database.connections` (driver pgsql).
     */
    public function postgres(string $name): string
    {
        $connection = $this->config->get("database.connections.{$name}");

        if (! is_array($connection)) {
            throw new InvalidArgumentException("Database connection [{$name}] is not configured in config/database.php.");
        }

        /** @var array<string, mixed> $parsed */
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($connection);

        if (($parsed['driver'] ?? null) !== 'pgsql') {
            throw new InvalidArgumentException("Database connection [{$name}] must use the pgsql driver.");
        }

        $host = $parsed['write']['host'] ?? $parsed['host'] ?? '127.0.0.1';
        $host = is_array($host) ? reset($host) : $host;

        $pairs = [
            'host' => is_string($host) ? $host : '127.0.0.1',
            'port' => is_numeric($parsed['port'] ?? null) ? (string) $parsed['port'] : '5432',
            'dbname' => self::string($parsed, 'database'),
            'user' => self::string($parsed, 'username'),
            'password' => self::string($parsed, 'password'),
            'sslmode' => self::string($parsed, 'sslmode'),
            'connect_timeout' => '5',
            'application_name' => 'harken',
        ];

        $parts = [];

        foreach ($pairs as $key => $value) {
            if ($value !== null) {
                $parts[] = $key."='".addcslashes($value, "'\\")."'";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
