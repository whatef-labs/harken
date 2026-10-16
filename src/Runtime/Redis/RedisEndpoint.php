<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Redis;

use SensitiveParameter;

/**
 * Where and how the runtime's own asynchronous Redis connections connect.
 *
 * $prefix is the key prefix the application's Redis client adds (Laravel's
 * `options.prefix`); keys shared with the application must carry it.
 */
final readonly class RedisEndpoint
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public ?string $username = null,
        #[SensitiveParameter] public ?string $password = null,
        public int $database = 0,
        public bool $tls = false,
        public string $prefix = '',
        public float $connectTimeout = 5.0,
    ) {}

    /**
     * The address without credentials, for logs and for the connector.
     */
    public function uri(): string
    {
        $host = str_contains($this->host, ':') && ! str_starts_with($this->host, '[') ? "[{$this->host}]" : $this->host;

        return sprintf('%s://%s:%d', $this->tls ? 'rediss' : 'redis', $host, $this->port);
    }

    public function hostIsIp(): bool
    {
        return filter_var(trim($this->host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['password' => $this->password === null ? null : '********'] + get_object_vars($this);
    }
}
