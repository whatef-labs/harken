<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

/**
 * Keeps the runtime's metrics in memory: counters, gauges and histograms,
 * each per label set. Exporters (the Prometheus endpoint, the log driver)
 * read it. Every call is an array update, cheap enough for the event loop.
 *
 * Values use base units (seconds, bytes), as Prometheus expects. Leader
 * takeover times get buckets from 50 ms to a minute; every other histogram
 * (handler and publish latencies) gets buckets from 50 µs to 5 s. A
 * histogram also keeps its largest value since resetMaxima(), for the log
 * driver.
 */
final class MetricsRegistry implements Metrics
{
    private const array LATENCY = [0.00005, 0.0001, 0.00025, 0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5];

    private const array TAKEOVER = [0.05, 0.1, 0.25, 0.5, 1, 2, 3, 4, 5, 6, 7, 8, 10, 15, 30, 60];

    /** @var array<string, array<string, float>> name => label key => value */
    private array $counters = [];

    /** @var array<string, array<string, float>> name => label key => value */
    private array $gauges = [];

    /** @var array<string, array<string, array{buckets: list<int>, sum: float, count: int, max: float}>> */
    private array $histograms = [];

    /** @var array<string, array<string, string>> label key => labels */
    private array $labels = [];

    public function increment(string $name, int $by = 1, array $labels = []): void
    {
        $key = $this->key($labels);
        $this->counters[$name][$key] = ($this->counters[$name][$key] ?? 0.0) + $by;
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $this->gauges[$name][$this->key($labels)] = $value;
    }

    public function observe(string $name, float $value, array $labels = []): void
    {
        $key = $this->key($labels);
        $bounds = self::buckets($name);
        $histogram = $this->histograms[$name][$key] ?? self::emptyHistogram($bounds);

        // Cumulative: every bucket whose bound is at least the value counts it.
        $histogram['buckets'] = array_map(
            static fn (int $count, float $bound): int => $value <= $bound ? $count + 1 : $count,
            $histogram['buckets'],
            $bounds,
        );
        $histogram['sum'] += $value;
        $histogram['count']++;
        $histogram['max'] = max($histogram['max'], $value);
        $this->histograms[$name][$key] = $histogram;
    }

    /**
     * Starts the largest value of every histogram over.
     */
    public function resetMaxima(): void
    {
        foreach ($this->histograms as $name => $series) {
            foreach ($series as $key => $histogram) {
                $histogram['max'] = 0.0;
                $this->histograms[$name][$key] = $histogram;
            }
        }
    }

    /**
     * Creates an empty histogram series, so its first observation shows up
     * as an increase in Prometheus instead of as the series' first value.
     *
     * @param  array<string, string>  $labels
     */
    public function declareHistogram(string $name, array $labels = []): void
    {
        $this->histograms[$name][$this->key($labels)] ??= self::emptyHistogram(self::buckets($name));
    }

    /**
     * @param  list<float>  $bounds
     * @return array{buckets: list<int>, sum: float, count: int, max: float}
     */
    private static function emptyHistogram(array $bounds): array
    {
        return ['buckets' => array_fill(0, count($bounds), 0), 'sum' => 0.0, 'count' => 0, 'max' => 0.0];
    }

    /**
     * @return list<float>
     */
    public static function buckets(string $name): array
    {
        return array_map(floatval(...), str_starts_with($name, 'harken_leader_') ? self::TAKEOVER : self::LATENCY);
    }

    /**
     * @return array<string, list<array{array<string, string>, float}>> name => [labels, value]
     */
    public function counters(): array
    {
        return $this->expand($this->counters);
    }

    /**
     * @return array<string, list<array{array<string, string>, float}>> name => [labels, value]
     */
    public function gauges(): array
    {
        return $this->expand($this->gauges);
    }

    /**
     * Cumulative bucket counts, in the order of buckets($name).
     *
     * @return array<string, list<array{array<string, string>, array{buckets: list<int>, sum: float, count: int, max: float}}>>
     */
    public function histograms(): array
    {
        $out = [];

        foreach ($this->histograms as $name => $series) {
            foreach ($series as $key => $histogram) {
                $out[$name][] = [$this->labels[$key] ?? [], $histogram];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, float>>  $metrics
     * @return array<string, list<array{array<string, string>, float}>>
     */
    private function expand(array $metrics): array
    {
        $out = [];

        foreach ($metrics as $name => $series) {
            foreach ($series as $key => $value) {
                $out[$name][] = [$this->labels[$key] ?? [], $value];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function key(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        ksort($labels);
        $key = (string) json_encode($labels);
        $this->labels[$key] ??= $labels;

        return $key;
    }
}
