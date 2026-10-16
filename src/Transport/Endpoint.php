<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

final readonly class Endpoint
{
    /**
     * @param  string|null  $nameserver  DNS server for resolving $host; null uses the system
     *                                   configuration. Not used when $host is an IP address.
     */
    public function __construct(
        public string $host,
        public int $port = 1883,
        public ?TlsOptions $tls = null,
        public float $connectTimeout = 5.0,
        public ?string $nameserver = null,
    ) {}

    public function hostIsIp(): bool
    {
        return filter_var(trim($this->host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    public function uri(): string
    {
        return sprintf('%s://%s:%d', $this->tls === null ? 'tcp' : 'tls', $this->host, $this->port);
    }
}
