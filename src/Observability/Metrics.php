<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

/**
 * Metrics sink. Drivers (Pulse, Prometheus) implement this; the runtime only
 * talks to the interface. Calls happen on the event loop and must not block.
 */
interface Metrics
{
    /**
     * @param  array<string, string>  $labels
     */
    public function increment(string $name, int $by = 1, array $labels = []): void;

    /**
     * Records a sample for a histogram-like metric (e.g. handler milliseconds).
     *
     * @param  array<string, string>  $labels
     */
    public function observe(string $name, float $value, array $labels = []): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function gauge(string $name, float $value, array $labels = []): void;
}
