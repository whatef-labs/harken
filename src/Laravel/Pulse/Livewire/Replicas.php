<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Pulse\Livewire;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Pulse card `<livewire:harken.replicas />`: every harken:start process,
 * its role, its broker connection and its load, as last reported.
 *
 * A replica that has not reported for two intervals is shown as gone; one
 * that stopped or went silent more than `ignore-after` ago (10 minutes by
 * default) is left out, since every process reports under a new client id.
 */
#[Lazy]
final class Replicas extends Card
{
    public int|string|null $ignoreAfter = 600;

    public function render(): Renderable
    {
        [$replicas, $time, $runAt] = $this->remember(function () {
            $interval = Config::get('harken.metrics.pulse_interval', 15);
            $silentAfter = 2 * (is_numeric($interval) ? (float) $interval : 15.0) + 5;
            $ignoreAfter = $this->ignoreAfterSeconds();

            return $this->values('harken_replica')
                ->map(static function (object $value, string $key) use ($silentAfter, $ignoreAfter): ?object {
                    /** @var object{timestamp: int, key: string, value: string} $value */
                    $updatedAt = CarbonImmutable::createFromTimestamp($value->timestamp);
                    $age = $updatedAt->diffInSeconds(CarbonImmutable::now(), absolute: true);

                    if ($ignoreAfter !== null && $age > $ignoreAfter) {
                        return null;
                    }

                    $status = json_decode($value->value, true);

                    if (! is_array($status)) {
                        return null;
                    }

                    $running = ($status['state'] ?? null) !== 'stopped' && $age <= $silentAfter;

                    return (object) [
                        'client_id' => $key,
                        'name' => (string) ($status['name'] ?? $key),
                        'connection' => (string) ($status['connection'] ?? ''),
                        'state' => $running ? (string) ($status['state'] ?? 'unknown') : (($status['state'] ?? null) === 'stopped' ? 'stopped' : 'gone'),
                        'running' => $running,
                        'role' => match ($status['leader'] ?? null) {
                            'leading' => 'leader',
                            'standby', 'idle' => 'standby',
                            default => null,
                        },
                        'mqtt' => (string) ($status['mqtt'] ?? 'unknown'),
                        'received_per_second' => is_numeric($status['received_per_second'] ?? null) ? (float) $status['received_per_second'] : null,
                        'memory_mb' => is_numeric($status['memory_mb'] ?? null) ? (float) $status['memory_mb'] : null,
                        'outbox_waiting' => is_numeric($status['outbox_waiting'] ?? null) ? (int) $status['outbox_waiting'] : null,
                        'last_gap_seconds' => is_numeric($status['last_gap_seconds'] ?? null) ? (float) $status['last_gap_seconds'] : null,
                        'updated_at' => $updatedAt,
                    ];
                })
                ->filter()
                ->sortBy([
                    static fn (object $a, object $b): int => [! $a->running, $a->role !== 'leader', $a->name] <=> [! $b->running, $b->role !== 'leader', $b->name],
                ]);
        }, (string) $this->ignoreAfter);

        return View::make('harken::pulse.replicas', [
            'replicas' => $replicas,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    private function ignoreAfterSeconds(): ?float
    {
        if ($this->ignoreAfter === null) {
            return null;
        }

        return is_numeric($this->ignoreAfter)
            ? (float) $this->ignoreAfter
            : CarbonInterval::createFromDateString($this->ignoreAfter)->totalSeconds;
    }
}
