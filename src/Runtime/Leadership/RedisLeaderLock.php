<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use React\Promise\PromiseInterface;
use UnexpectedValueException;
use Whatef\Harken\Runtime\Redis\RedisClient;

/**
 * Leader lock on Redis: SET NX PX to acquire, owner-checked PEXPIRE to renew
 * and DEL to release (Lua scripts, so check and change are atomic).
 *
 * A second key records when a leader was last seen alive, on the Redis
 * server clock, so the next leader can report how long the lock was vacant.
 *
 * Uses its own asynchronous Redis connection.
 */
final readonly class RedisLeaderLock implements LeaderLock
{
    private const string ACQUIRE = <<<'LUA'
        if redis.call('set', KEYS[1], ARGV[1], 'NX', 'PX', ARGV[2]) then
            local t = redis.call('time')
            local now = t[1] * 1000 + math.floor(t[2] / 1000)
            local last = redis.call('get', KEYS[2])
            redis.call('set', KEYS[2], now, 'PX', 86400000)
            if last then return now - tonumber(last) end
            return -1
        end
        return false
        LUA;

    private const string RENEW = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            redis.call('pexpire', KEYS[1], ARGV[2])
            local t = redis.call('time')
            redis.call('set', KEYS[2], t[1] * 1000 + math.floor(t[2] / 1000), 'PX', 86400000)
            return 1
        end
        return 0
        LUA;

    private const string RELEASE = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            local t = redis.call('time')
            redis.call('set', KEYS[2], t[1] * 1000 + math.floor(t[2] / 1000), 'PX', 86400000)
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

    public function __construct(
        private RedisClient $redis,
        public string $key,
    ) {}

    public function acquire(string $owner, float $leaseSeconds): PromiseInterface
    {
        return $this->script(self::ACQUIRE, $owner, (string) self::milliseconds($leaseSeconds))->then(
            static function (mixed $vacantMs): ?LeaseGrant {
                if ($vacantMs === null) {
                    return null;
                }

                return new LeaseGrant(is_numeric($vacantMs) && (int) $vacantMs >= 0 ? (int) $vacantMs / 1000 : null);
            },
        );
    }

    public function renew(string $owner, float $leaseSeconds): PromiseInterface
    {
        return $this->script(self::RENEW, $owner, (string) self::milliseconds($leaseSeconds))
            ->then(static fn (mixed $renewed): bool => (int) $renewed === 1);
    }

    public function release(string $owner): PromiseInterface
    {
        return $this->script(self::RELEASE, $owner)->then(static fn (): null => null);
    }

    public function close(): void
    {
        $this->redis->close();
    }

    public function name(): string
    {
        return 'redis';
    }

    /**
     * @return PromiseInterface<mixed>
     */
    private function script(string $lua, string ...$args): PromiseInterface
    {
        return $this->redis->call('eval', $lua, 2, $this->key, $this->key.':seen', ...$args);
    }

    private static function milliseconds(float $seconds): int
    {
        $ms = (int) round($seconds * 1000);

        if ($ms < 1) {
            throw new UnexpectedValueException('The lease must be at least one millisecond.');
        }

        return $ms;
    }
}
