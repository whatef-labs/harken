<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use SensitiveParameter;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Reconnect\Backoff;
use Whatef\Harken\Transport\Endpoint;

final readonly class ConnectionOptions
{
    public function __construct(
        public string $name,
        public Endpoint $endpoint,
        public string $clientId,
        public Backoff $backoff,
        public int $keepAlive = 60,
        public bool $cleanSession = true,
        public ?string $username = null,
        #[SensitiveParameter] public ?string $password = null,
        public ProtocolVersion $protocol = ProtocolVersion::V5_0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['password' => $this->password === null ? null : '********'] + get_object_vars($this);
    }
}
