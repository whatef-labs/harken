<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Livewire\Livewire;

/**
 * Pulse card `<livewire:harken.messages />`: messages received and
 * published per broker connection, with failures and broker trouble.
 */
#[Lazy]
final class Messages extends Card
{
    public const array TYPES = ['harken_received', 'harken_published', 'harken_failed', 'harken_dropped', 'harken_reconnects', 'harken_takeovers'];

    public function render(): Renderable
    {
        [$connections, $time, $runAt] = $this->remember(fn () => $this->graph(self::TYPES, 'sum'));

        /** @var Collection<string, Collection<string, Collection<string, int|null>>> $connections */
        $bucketSeconds = $this->periodAsInterval()->totalSeconds / 60;

        if (Livewire::isLivewireRequest()) {
            $this->dispatch('harken-messages-chart-update', connections: $connections);
        }

        return View::make('harken::pulse.messages', [
            'connections' => $connections->map(static fn (Collection $readings): object => (object) [
                'readings' => $readings,
                'totals' => $readings->map(static fn (Collection $values): int => (int) $values->sum()),
                'peak' => (int) ($readings->get('harken_received')?->max() ?? 0) / $bucketSeconds,
                // The time covered by reports, so a runtime started ten minutes ago averages over ten minutes.
                'seconds' => max(1, $readings->get('harken_received')?->filter(static fn (?int $value): bool => $value !== null)->count() ?? 1) * $bucketSeconds,
            ]),
            'bucketSeconds' => $bucketSeconds,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
