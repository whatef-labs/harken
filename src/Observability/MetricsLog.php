<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Whatef\Harken\Runtime\Sources\Source;

/**
 * The `log` metrics driver: one log line every interval with what changed
 * since the previous line (counters as deltas, histograms as count and
 * average) and the current gauges. Enough to follow a runtime without a
 * metrics system.
 */
final class MetricsLog implements Source
{
    private ?TimerInterface $timer = null;

    private ?LoopInterface $loop = null;

    /** @var array<string, float> */
    private array $lastCounters = [];

    /** @var array<string, array{float, int}> */
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
            foreach ($series as [$labels, $histogram]) {
                $key = self::key($name, $labels);
                [$lastSum, $lastCount] = $this->lastHistograms[$key] ?? [0.0, 0];
                $count = $histogram['count'] - $lastCount;
                $summary["{$key}.count"] = $count;
                $summary["{$key}.avg"] = $count === 0 ? 0.0 : round(($histogram['sum'] - $lastSum) / $count, 3);
                $this->lastHistograms[$key] = [$histogram['sum'], $histogram['count']];
            }
        }

        ksort($summary);

        return $summary;
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
