<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

use SensitiveParameter;

/**
 * Fields after $will are MQTT 5 only; the 3.1.1 codec ignores them. On MQTT 5,
 * $cleanSession is sent as the Clean Start flag and the session outlives the
 * connection for $sessionExpiryInterval seconds.
 */
final readonly class Connect implements Packet
{
    /**
     * @param  array<string, string|list<string>>  $userProperties
     */
    public function __construct(
        public string $clientId,
        public int $keepAlive = 60,
        public bool $cleanSession = true,
        public ?string $username = null,
        #[SensitiveParameter] public ?string $password = null,
        public ?Publish $will = null,
        public int $sessionExpiryInterval = 0,
        public ?int $receiveMaximum = null,
        public ?int $maximumPacketSize = null,
        public int $topicAliasMaximum = 0,
        public bool $requestResponseInformation = false,
        public bool $requestProblemInformation = true,
        public array $userProperties = [],
        public int $willDelayInterval = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['password' => $this->password === null ? null : '********'] + get_object_vars($this);
    }
}
