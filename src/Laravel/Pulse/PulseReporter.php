<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Pulse;

use Closure;
use Laravel\Pulse\Pulse;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;
use Whatef\Harken\Observability\MetricsRegistry;
use Whatef\Harken\Runtime\Sources\Source;

/**
 * The `pulse` metrics driver: every interval, records what changed in the
 * runtime's metrics to Laravel Pulse and ingests it, so the Harken cards on
 * the Pulse dashboard show this replica.
 *
 * It records a handful of aggregated entries per interval, never one per
 * message, so Pulse's storage load does not grow with MQTT traffic.
 *
 * - Totals per broker connection (graphs): harken_received,
 *   harken_published, harken_failed, harken_dropped, harken_reconnects,
 *   harken_takeovers.
 * - Per route or NOTIFY channel (table): harken_route_handled,
 *   harken_route_failed, harken_route_slow, harken_route_time (microseconds).
 * - The replica's status (harken_replica value).
 */
final class PulseReporter implements Source
{
    private ?LoopInterface $loop = null;

    private ?TimerInterface $timer = null;

    private ?TimerInterface $first = null;

    /** @var array<string, float> */
    private array $last = [];

    private ?float $lastReportAt = null;

    /** @var Closure(): ?array<string, mixed> */
    private readonly Closure $status;

    /** @var (Closure(Throwable): void)|null */
    private readonly ?Closure $onError;

    /**
     * @param  callable(): ?array<string, mixed>  $status  Runtime::status(), or null before it starts; called before the counters are read.
     * @param  (callable(Throwable): void)|null  $onError
     */
    public function __construct(
        private readonly Pulse $pulse,
        private readonly MetricsRegistry $metrics,
        callable $status,
        public readonly string $connection = 'default',
        public readonly float $intervalSeconds = 15.0,
        ?callable $onError = null,
    ) {
        $this->status = $status(...);
        $this->onError = $onError === null ? null : $onError(...);
    }

    public function name(): string
    {
        return 'pulse';
    }

    public function start(LoopInterface $loop): void
    {
        $this->stop();
        $this->loop = $loop;
        $this->lastReportAt = microtime(true);
        $this->timer = $loop->addPeriodicTimer($this->intervalSeconds, fn () => $this->report());
        // Once soon after starting too, so a new replica shows up at once.
        $this->first = $loop->addTimer(min(1.0, $this->intervalSeconds), function (): void {
            $this->first = null;
            $this->report();
        });
    }

    /**
     * Reports what is left, marks the replica stopped, and stops.
     */
    public function stop(): void
    {
        if ($this->timer === null) {
            return;
        }

        $this->loop?->cancelTimer($this->timer);
        $this->timer = null;

        if ($this->first !== null) {
            $this->loop?->cancelTimer($this->first);
            $this->first = null;
        }

        $this->report(stopped: true);
    }

    public function report(bool $stopped = false): void
    {
        try {
            $this->record($stopped);
            $this->pulse->ingest();
        } catch (Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
        }
    }

    private function record(bool $stopped): void
    {
        $now = microtime(true);
        $elapsed = max(0.001, $now - ($this->lastReportAt ?? $now));
        $this->lastReportAt = $now;
        $timestamp = (int) $now;
        // First: the callback may bring counters up to date.
        $status = ($this->status)();
        // What each counter series gained since the last report.
        $deltas = [];

        foreach ($this->metrics->counters() as $metric => $series) {
            foreach ($series as [$labels, $value]) {
                $deltas[$metric][] = [$labels, $this->delta($metric.json_encode($labels), $value)];
            }
        }

        $total = static fn (string $metric): float => array_sum(array_column($deltas[$metric] ?? [], 1));

        $received = $total('harken_messages_received_total');

        foreach ([
            'harken_received' => $received,
            'harken_published' => $total('harken_messages_published_total'),
            'harken_failed' => $total('harken_handlers_failed_total'),
            'harken_dropped' => $total('harken_outbox_dropped_total'),
            'harken_reconnects' => $total('harken_reconnects_total'),
            'harken_takeovers' => $total('harken_leader_takeovers_total'),
        ] as $type => $value) {
            $this->pulse->record($type, $this->connection, (int) $value, $timestamp)->sum()->onlyBuckets();
        }

        $this->routes($deltas, $timestamp);

        if ($status !== null) {
            $this->pulse->set('harken_replica', $this->replicaKey($status), (string) json_encode([
                'name' => $this->replicaName($status),
                'connection' => $this->connection,
                'state' => $stopped ? 'stopped' : ($status['state'] ?? 'unknown'),
                'mqtt' => $status['mqtt']['state'] ?? 'unknown',
                'leader' => $status['leader']['state'] ?? null,
                'received_per_second' => round($received / $elapsed, 1),
                'memory_mb' => $status['memory_mb'] ?? null,
                'outbox_waiting' => is_array($status['outbox']['waiting'] ?? null) ? array_sum($status['outbox']['waiting']) : null,
                'last_gap_seconds' => $status['leader']['last_gap_seconds'] ?? null,
                'started_at' => $status['started_at'] ?? null,
            ]), $timestamp);
        }
    }

    /**
     * @param  array<string, list<array{array<string, string>, float}>>  $deltas
     */
    private function routes(array $deltas, int $timestamp): void
    {
        $perRoute = [];

        foreach ([
            'harken_messages_handled_total' => 'harken_route_handled',
            'harken_notifications_handled_total' => 'harken_route_handled',
            'harken_handlers_failed_total' => 'harken_route_failed',
            'harken_handlers_slow_total' => 'harken_route_slow',
        ] as $metric => $type) {
            foreach ($deltas[$metric] ?? [] as [$labels, $delta]) {
                $route = self::route($labels);
                $perRoute[$route][$type] = ($perRoute[$route][$type] ?? 0.0) + $delta;
            }
        }

        foreach ($this->metrics->histograms()['harken_handler_seconds'] ?? [] as [$labels, $histogram]) {
            $route = self::route($labels);
            $perRoute[$route]['harken_route_time'] = ($perRoute[$route]['harken_route_time'] ?? 0.0)
                + $this->delta('harken_handler_seconds'.json_encode($labels), $histogram['sum']) * 1_000_000;
        }

        foreach ($perRoute as $route => $types) {
            if (($types['harken_route_handled'] ?? 0) + ($types['harken_route_failed'] ?? 0) <= 0) {
                continue;
            }

            foreach (['harken_route_handled', 'harken_route_failed', 'harken_route_slow', 'harken_route_time'] as $type) {
                // Kept as raw entries too: Pulse reads route names from them.
                $this->pulse->record($type, $route, (int) round($types[$type] ?? 0), $timestamp)->sum();
            }
        }
    }

    private function delta(string $series, float $value): float
    {
        $delta = $value - ($this->last[$series] ?? 0.0);
        $this->last[$series] = $value;

        return max(0.0, $delta);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function route(array $labels): string
    {
        return isset($labels['channel']) ? "NOTIFY {$labels['channel']}" : ($labels['route'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function replicaKey(array $status): string
    {
        return is_string($status['client_id'] ?? null) ? $status['client_id'] : $this->replicaName($status);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function replicaName(array $status): string
    {
        $host = is_string($status['host'] ?? null) ? $status['host'] : 'host';
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;

        return "{$host} #{$pid}";
    }
}
