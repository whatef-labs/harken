<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Whatef\Harken\Runtime\Sources\Source;

/**
 * The `log` metrics driver: one log line every interval with what changed
 * since the previous line (counters as deltas; histograms as count,
 * average, p50 and p99 estimated from the buckets, and maximum) and the
 * current gauges. Enough to follow a runtime without a metrics system.
 */
final class MetricsLog implements Source
{
    private ?TimerInterface $timer = null;

    private ?LoopInterface $loop = null;

    /** @var array<string, float> */
    private array $lastCounters = [];

    /** @var array<string, array{float, int, list<int>}> Sum, count and buckets at the previous line. */
    private array $lastHistograms = [];

    public function __construct(
        private readonly MetricsRegistry $metrics,
        private readonly LoggerInterface $logger,
        public readonly float $intervalSeconds = 60.0,
    ) {}

    public function name(): string
    {
        return 'metrics log';
    }

    public function start(LoopInterface $loop): void
    {
        $this->stop();
        $this->loop = $loop;
        $this->timer = $loop->addPeriodicTimer($this->intervalSeconds, $this->write(...));
    }

    public function stop(): void
    {
        if ($this->timer !== null) {
            $this->loop?->cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    /**
     * Logs the line now (also called by the timer).
     */
    public function write(): void
    {
        $this->logger->info('Harken metrics', $this->summary());
    }

    /**
     * @return array<string, float|int>
     */
    public function summary(): array
    {
        $summary = [];

        foreach ($this->metrics->counters() as $name => $series) {
            foreach ($series as [$labels, $value]) {
                $key = self::key($name, $labels);
                $summary[$key] = $value - ($this->lastCounters[$key] ?? 0.0);
                $this->lastCounters[$key] = $value;
            }
        }

        foreach ($this->metrics->gauges() as $name => $series) {
            foreach ($series as [$labels, $value]) {
                $summary[self::key($name, $labels)] = $value;
            }
        }

        foreach ($this->metrics->histograms() as $name => $series) {
            $bounds = MetricsRegistry::buckets($name);

            foreach ($series as [$labels, $histogram]) {
                $key = self::key($name, $labels);
                [$lastSum, $lastCount, $lastBuckets] = $this->lastHistograms[$key] ?? [0.0, 0, array_fill(0, count($bounds), 0)];
                $count = $histogram['count'] - $lastCount;
                $buckets = array_map(static fn (int $now, int $before): int => $now - $before, $histogram['buckets'], $lastBuckets);
                $summary["{$key}.count"] = $count;
                $summary["{$key}.avg"] = $count === 0 ? 0.0 : round(($histogram['sum'] - $lastSum) / $count, 6);
                $summary["{$key}.p50"] = self::quantile(0.5, $bounds, $buckets, $count, $histogram['max']);
                $summary["{$key}.p99"] = self::quantile(0.99, $bounds, $buckets, $count, $histogram['max']);
                $summary["{$key}.max"] = $count === 0 ? 0.0 : round($histogram['max'], 6);
                $this->lastHistograms[$key] = [$histogram['sum'], $histogram['count'], $histogram['buckets']];
            }
        }

        $this->metrics->resetMaxima();
        ksort($summary);

        return $summary;
    }

    /**
     * The $q quantile of the values counted in $buckets (cumulative counts
     * per upper bound), interpolated linearly inside its bucket as
     * Prometheus' histogram_quantile() does, and never above the maximum.
     *
     * @param  list<float>  $bounds
     * @param  list<int>  $buckets
     */
    private static function quantile(float $q, array $bounds, array $buckets, int $count, float $max): float
    {
        if ($count === 0) {
            return 0.0;
        }

        $rank = $q * $count;
        $lower = 0.0;
        $below = 0;

        foreach ($bounds as $i => $upper) {
            if ($buckets[$i] >= $rank) {
                return round(min($max, $lower + ($upper - $lower) * ($rank - $below) / ($buckets[$i] - $below)), 6);
            }

            $lower = $upper;
            $below = $buckets[$i];
        }

        return round($max, 6); // above the highest bound
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function key(string $name, array $labels): string
    {
        $name = (string) preg_replace('/^harken_/', '', $name);

        return $labels === [] ? $name : $name.'{'.implode(',', $labels).'}';
    }
}
