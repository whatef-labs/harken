<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use SensitiveParameter;

final readonly class Connect implements Packet
{
    public function __construct(
        public string $clientId,
        public int $keepAlive = 60,
        public bool $cleanSession = true,
        public ?string $username = null,
        #[SensitiveParameter] public ?string $password = null,
        public ?Publish $will = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'keepAlive' => $this->keepAlive,
            'cleanSession' => $this->cleanSession,
            'username' => $this->username,
            'password' => $this->password === null ? null : '********',
            'will' => $this->will,
        ];
    }
}
