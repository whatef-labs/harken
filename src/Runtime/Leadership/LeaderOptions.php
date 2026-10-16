<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use InvalidArgumentException;

/**
 * Settings of the leader election and the leader connection.
 */
final readonly class LeaderOptions
{
    public const array LOCKS = ['redis', 'pgsql', 'none'];

    /**
     * @param  string  $clientId  Fixed client id of the leader connection, shared by all replicas.
     * @param  int  $sessionExpiry  Seconds the broker keeps the leader session (and queues QoS 1
     *                              messages) while no leader is connected. MQTT 5 only; MQTT 3.1.1
     *                              brokers use their own setting.
     * @param  string  $lock  "redis", "pgsql" or "none".
     * @param  string|null  $lockConnection  Redis or database connection name for the lock.
     */
    public function __construct(
        public string $clientId,
        public int $sessionExpiry = 300,
        public string $lock = 'redis',
        public ?string $lockConnection = null,
        public float $lease = 5.0,
        public float $retryInterval = 0.25,
    ) {
        if (! in_array($lock, self::LOCKS, true)) {
            throw new InvalidArgumentException("Unknown leader lock [{$lock}]; use redis, pgsql or none.");
        }

        if ($lease < 1.0) {
            throw new InvalidArgumentException('The leader lease must be at least 1 second.');
        }

        if ($retryInterval <= 0 || $retryInterval >= $lease) {
            throw new InvalidArgumentException('The leader retry interval must be greater than zero and shorter than the lease.');
        }

        if ($sessionExpiry < (int) ceil($lease)) {
            throw new InvalidArgumentException('The leader session expiry must be at least the lease, or queued messages expire before the next leader connects.');
        }
    }

    /**
     * The lock key (or advisory lock name): one lock per leader client id.
     */
    public function lockKey(): string
    {
        return "harken:leader:{$this->clientId}";
    }
}
