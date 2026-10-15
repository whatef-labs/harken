<?php

declare(strict_types=1);

/*
 * Small helpers shared by the spike scripts. Spikes are throwaway
 * experiments; nothing here is part of the package.
 */

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @param  list<float>  $samples
 * @return array{n: int, p50: float, p99: float, max: float}
 */
function spike_percentiles(array $samples): array
{
    if ($samples === []) {
        return ['n' => 0, 'p50' => 0.0, 'p99' => 0.0, 'max' => 0.0];
    }

    sort($samples);
    $pick = static fn (float $p): float => $samples[(int) min(count($samples) - 1, floor($p * count($samples)))];

    return [
        'n' => count($samples),
        'p50' => round($pick(0.50), 3),
        'p99' => round($pick(0.99), 3),
        'max' => round(end($samples), 3),
    ];
}

function spike_ms(int $hrtimeStart): float
{
    return (hrtime(true) - $hrtimeStart) / 1e6;
}

function spike_line(string $label, mixed $value): void
{
    printf("%-46s %s\n", $label, is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES));
}

/**
 * Measures how late a 1 ms periodic timer fires while $work runs on the loop.
 *
 * @return array{n: int, p50: float, p99: float, max: float}
 */
function spike_loop_lag(React\EventLoop\LoopInterface $loop, float $seconds): array
{
    $lags = [];
    $last = hrtime(true);
    $timer = $loop->addPeriodicTimer(0.001, function () use (&$lags, &$last) {
        $now = hrtime(true);
        $lags[] = max(0.0, ($now - $last) / 1e6 - 1.0);
        $last = $now;
    });

    React\Async\await(React\Promise\Timer\sleep($seconds));
    $loop->cancelTimer($timer);

    return spike_percentiles($lags);
}
