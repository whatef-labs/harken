<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use stdClass;

/**
 * Pulse card `<livewire:harken.handlers />`: per route and NOTIFY channel,
 * how many messages its handler took, how many failed or were slow, and
 * how long it took on average.
 */
#[Lazy]
final class Handlers extends Card
{
    /** @var 'handled'|'failed'|'slow'|'time' */
    #[Url(as: 'harken-handlers')]
    public string $orderBy = 'handled';

    public function render(): Renderable
    {
        [$handlers, $time, $runAt] = $this->remember(
            fn () => $this->aggregateTypes(
                ['harken_route_handled', 'harken_route_failed', 'harken_route_slow', 'harken_route_time'],
                'sum',
                match ($this->orderBy) {
                    'failed' => 'harken_route_failed',
                    'slow' => 'harken_route_slow',
                    'time' => 'harken_route_time',
                    default => 'harken_route_handled',
                },
            )->map(static function (stdClass $row): object {
                $handled = (int) $row->harken_route_handled;

                return (object) [
                    'route' => (string) $row->key,
                    'handled' => $handled,
                    'failed' => (int) $row->harken_route_failed,
                    'slow' => (int) $row->harken_route_slow,
                    // Microseconds in total, over the runs that returned.
                    'average_ms' => $handled > 0 ? (float) $row->harken_route_time / $handled / 1000 : null,
                ];
            }),
            $this->orderBy,
        );

        return View::make('harken::pulse.handlers', [
            'handlers' => $handlers,
            'threshold' => Config::get('harken.handlers.slow_threshold_ms', 100),
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
