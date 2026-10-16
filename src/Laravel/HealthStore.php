<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Runtime status heartbeats in the Laravel cache, read by `harken:status`.
 *
 * Each process writes its own key; an index key lists them, so the status
 * command can find every runtime without scanning the cache.
 */
final class HealthStore
{
    public const string INDEX = 'harken:status:index';

    public function __construct(
        private readonly Cache $cache,
        public readonly int $staleAfter = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $status  Runtime::status()
     */
    public function write(array $status): void
    {
        $key = self::key($status);
        $this->cache->put($key, $status, max(60, $this->staleAfter * 4));

        /** @var list<string> $index */
        $index = (array) $this->cache->get(self::INDEX, []);

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            $this->cache->forever(self::INDEX, $index);
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function forget(array $status): void
    {
        $key = self::key($status);
        $this->cache->forget($key);
        $this->cache->forever(self::INDEX, array_values(array_diff((array) $this->cache->get(self::INDEX, []), [$key])));
    }

    /**
     * Every runtime status still in the cache. Keys that expired are dropped
     * from the index, and so is a stale status when a newer runtime of the
     * same connection runs on the same host: the process it belonged to was
     * replaced (a restarted container keeps its hostname but not its PID).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?float $now = null): array
    {
        $now ??= microtime(true);

        /** @var list<string> $index */
        $index = (array) $this->cache->get(self::INDEX, []);
        $found = [];

        foreach ($index as $key) {
            $status = $this->cache->get($key);

            if (is_array($status)) {
                /** @var array<string, mixed> $status */
                $found[$key] = $status;
            }
        }

        $newest = [];

        foreach ($found as $status) {
            $slot = self::slot($status);
            $newest[$slot] = max($newest[$slot] ?? 0.0, self::number($status['started_at'] ?? null));
        }

        foreach ($found as $key => $status) {
            $replaced = self::number($status['started_at'] ?? null) < $newest[self::slot($status)];
            $stale = $now - self::number($status['heartbeat_at'] ?? null) > $this->staleAfter;

            if ($replaced && $stale) {
                $this->cache->forget($key);
                unset($found[$key]);
            }
        }

        if (array_keys($found) !== $index) {
            $this->cache->forever(self::INDEX, array_keys($found));
        }

        return array_values($found);
    }

    /**
     * Why a runtime is unhealthy, or null when it is healthy: its heartbeat
     * is recent and its broker connection is up (or was lost only moments
     * ago).
     *
     * @param  array<string, mixed>  $status
     */
    public function problem(array $status, ?float $now = null): ?string
    {
        $now ??= microtime(true);
        $heartbeat = is_numeric($status['heartbeat_at'] ?? null) ? (float) $status['heartbeat_at'] : 0.0;

        if ($now - $heartbeat > $this->staleAfter) {
            return sprintf('no heartbeat for %d s', (int) ($now - $heartbeat));
        }

        if (($status['state'] ?? null) !== 'running') {
            return 'runtime is '.(is_string($status['state'] ?? null) ? $status['state'] : 'unknown');
        }

        $mqtt = is_array($status['mqtt'] ?? null) ? $status['mqtt'] : [];

        if (($mqtt['state'] ?? null) === 'connected') {
            return null;
        }

        $since = $mqtt['disconnected_at'] ?? $status['started_at'] ?? null;

        if (is_numeric($since) && $now - (float) $since <= $this->staleAfter) {
            return null;
        }

        return 'not connected to the broker';
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private static function slot(array $status): string
    {
        return (is_string($status['host'] ?? null) ? $status['host'] : 'host').'|'.(is_string($status['connection'] ?? null) ? $status['connection'] : 'default');
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private static function key(array $status): string
    {
        return sprintf(
            'harken:status:%s:%s:%s',
            is_string($status['host'] ?? null) ? $status['host'] : 'host',
            is_string($status['connection'] ?? null) ? $status['connection'] : 'default',
            is_int($status['pid'] ?? null) ? $status['pid'] : 0,
        );
    }
}
