<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use SensitiveParameter;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Reconnect\Backoff;
use Whatef\Harken\Transport\Endpoint;

final readonly class ConnectionOptions
{
    /**
     * @param  int  $sessionExpiry  MQTT 5: seconds the broker keeps the session after the
     *                              connection closes. MQTT 3.1.1 brokers use their own setting.
     * @param  int  $maxQueued  Publishes that may wait for the connection (while it is
     *                          reconnecting, or beyond the broker's Receive Maximum) before
     *                          further publishes are rejected.
     */
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
        public int $sessionExpiry = 0,
        public int $maxQueued = 10000,
    ) {}

    /**
     * The same broker and credentials under another identity, for example
     * the leader's fixed client id with a persistent session.
     */
    public function withSession(string $clientId, bool $cleanSession, int $sessionExpiry): self
    {
        return new self(
            $this->name,
            $this->endpoint,
            $clientId,
            $this->backoff,
            $this->keepAlive,
            $cleanSession,
            $this->username,
            $this->password,
            $this->protocol,
            $sessionExpiry,
            $this->maxQueued,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['password' => $this->password === null ? null : '********'] + get_object_vars($this);
    }
}
