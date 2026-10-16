<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Whatef\Harken\Laravel\HealthStore;

/**
 * Reports the runtimes' heartbeats (connection state, last loop tick,
 * leader state, outbox, memory) from the cache, and exits non-zero when one
 * is unhealthy, so it can serve as a container health check.
 *
 * By default only runtimes on this host are checked, which is what a
 * container health check needs; --all lists every runtime.
 */
#[AsCommand(name: 'harken:status')]
final class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'harken:status
        {--all : Show the runtimes of every host}
        {--json : Print the raw status as JSON}';

    /** @var string */
    protected $description = 'Show the health of running Harken runtimes';

    public function handle(CacheFactory $cache, Config $config): int
    {
        $store = $config->get('harken.health.cache_store');
        $staleAfter = $config->get('harken.health.stale_after', 30);
        $health = new HealthStore(
            $cache->store(is_string($store) && $store !== '' ? $store : null),
            is_numeric($staleAfter) ? (int) $staleAfter : 30,
        );

        $host = gethostname() ?: null;
        $statuses = array_values(array_filter(
            $health->all(),
            fn (array $status): bool => $this->option('all') || ($status['host'] ?? null) === $host,
        ));

        if ($this->option('json')) {
            $this->line((string) json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($statuses === []) {
            $this->components->error($this->option('all') ? 'No Harken runtime is running.' : "No Harken runtime is running on [{$host}].");

            return self::FAILURE;
        }

        $now = microtime(true);
        $healthy = true;
        $rows = [];

        foreach ($statuses as $status) {
            $problem = $health->problem($status, $now);
            $healthy = $healthy && $problem === null;
            $mqtt = is_array($status['mqtt'] ?? null) ? $status['mqtt'] : [];
            $leader = is_array($status['leader'] ?? null) ? $status['leader'] : null;
            $outbox = is_array($status['outbox'] ?? null) ? $status['outbox'] : null;
            $waiting = is_array($outbox['waiting'] ?? null) ? array_sum(array_map('intval', $outbox['waiting'])) : null;

            $rows[] = [
                self::text($status['host'] ?? '?').':'.self::text($status['pid'] ?? '?'),
                self::text($status['connection'] ?? '?'),
                self::text($mqtt['state'] ?? '?'),
                $leader === null ? '-' : self::text($leader['state'] ?? '?'),
                sprintf('%.1f s', $now - (is_numeric($status['heartbeat_at'] ?? null) ? (float) $status['heartbeat_at'] : 0.0)),
                self::text($mqtt['received'] ?? 0).' / '.self::text($mqtt['published'] ?? 0),
                $waiting === null ? '-' : (string) $waiting,
                self::text($status['memory_mb'] ?? '?').' MB',
                $problem ?? 'ok',
            ];
        }

        if (! $this->option('json')) {
            $this->table(['Runtime', 'Connection', 'MQTT', 'Leader', 'Heartbeat', 'Received / published', 'Outbox', 'Memory', 'Health'], $rows);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '?';
    }
}
