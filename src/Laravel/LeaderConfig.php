<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use React\EventLoop\LoopInterface;
use Whatef\Harken\Runtime\Leadership\LeaderLock;
use Whatef\Harken\Runtime\Leadership\LeaderOptions;
use Whatef\Harken\Runtime\Leadership\NullLeaderLock;
use Whatef\Harken\Runtime\Leadership\PgAdvisoryLeaderLock;
use Whatef\Harken\Runtime\Leadership\RedisLeaderLock;
use Whatef\Harken\Runtime\Postgres\AsyncPgConnection;
use Whatef\Harken\Runtime\Redis\RedisClient;

/**
 * Turns the `leader` block of a `harken.connections.*` entry into leader
 * options and the lock they name.
 */
final class LeaderConfig
{
    /**
     * @param  array<string, mixed>  $connection  The whole connection block.
     */
    public static function options(array $connection): LeaderOptions
    {
        $leader = is_array($connection['leader'] ?? null) ? $connection['leader'] : [];
        $prefix = $connection['client_id_prefix'] ?? 'harken';
        $clientId = $leader['client_id'] ?? null;
        $lockConnection = $leader['lock_connection'] ?? null;

        return new LeaderOptions(
            clientId: is_string($clientId) && $clientId !== '' ? $clientId : (is_string($prefix) ? $prefix : 'harken').'-leader',
            sessionExpiry: is_numeric($leader['session_expiry'] ?? null) ? (int) $leader['session_expiry'] : 300,
            lock: is_string($leader['lock'] ?? null) ? $leader['lock'] : 'redis',
            lockConnection: is_string($lockConnection) && $lockConnection !== '' ? $lockConnection : null,
            lease: is_numeric($leader['lease'] ?? null) ? (float) $leader['lease'] : 5.0,
            retryInterval: is_numeric($leader['retry_interval'] ?? null) ? (float) $leader['retry_interval'] : 0.25,
        );
    }

    public static function lock(LeaderOptions $options, StoreConfig $stores, LoopInterface $loop): LeaderLock
    {
        return match ($options->lock) {
            'redis' => self::redisLock($options, $stores, $loop),
            'pgsql' => new PgAdvisoryLeaderLock(
                new AsyncPgConnection($loop, $stores->postgres($options->lockConnection ?? 'pgsql')),
                $options->lockKey(),
            ),
            default => new NullLeaderLock,
        };
    }

    private static function redisLock(LeaderOptions $options, StoreConfig $stores, LoopInterface $loop): RedisLeaderLock
    {
        $endpoint = $stores->redis($options->lockConnection ?? 'default');

        return new RedisLeaderLock(new RedisClient($endpoint, $loop), $endpoint->prefix.$options->lockKey());
    }
}
