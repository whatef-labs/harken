<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

final class NullMetrics implements Metrics
{
    public function increment(string $name, int $by = 1, array $labels = []): void {}

    public function observe(string $name, float $value, array $labels = []): void {}

    public function gauge(string $name, float $value, array $labels = []): void {}
}
