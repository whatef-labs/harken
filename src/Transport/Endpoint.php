<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port = 1883,
        public ?TlsOptions $tls = null,
        public float $connectTimeout = 5.0,
    ) {}

    public function uri(): string
    {
        return sprintf('%s://%s:%d', $this->tls === null ? 'tcp' : 'tls', $this->host, $this->port);
    }
}
