<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Pulse as PulseRecorder;
use Livewire\Livewire;
use Whatef\Harken\Laravel\Pulse\Livewire\Handlers;
use Whatef\Harken\Laravel\Pulse\Livewire\Messages;
use Whatef\Harken\Laravel\Pulse\Livewire\Replicas;
use Whatef\Harken\Laravel\Pulse\PulseReporter;
use Whatef\Harken\Observability\MetricsRegistry;

/**
 * @param  array<string, mixed>  $status
 */
function replica(string $clientId, array $status, int $age = 0): void
{
    Pulse::set('harken_replica', $clientId, (string) json_encode($status + [
        'connection' => 'default',
        'state' => 'running',
        'mqtt' => 'connected',
        'leader' => null,
        'received_per_second' => 0,
        'memory_mb' => 30.0,
        'outbox_waiting' => 0,
        'last_gap_seconds' => null,
    ]), now()->subSeconds($age));
}

beforeEach(function () {
    Livewire::withoutLazyLoading();
});

it('registers the cards with Livewire', function () {
    expect(Blade::render('<livewire:harken.messages />'))->toContain('Harken')
        ->and(Blade::render('<livewire:harken.replicas />'))->toContain('Harken Replicas')
        ->and(Blade::render('<livewire:harken.handlers />'))->toContain('Harken Handlers');
});

it('shows messages received and published, with failures and broker trouble', function () {
    $metrics = new MetricsRegistry;
    $metrics->increment('harken_messages_received_total', 1_200, ['connection' => 'main']);
    $metrics->increment('harken_messages_published_total', 300, ['connection' => 'main']);
    $metrics->increment('harken_handlers_failed_total', 4, ['route' => 'a']);
    $metrics->increment('harken_reconnects_total', 2, ['connection' => 'main']);
    (new PulseReporter(app(PulseRecorder::class), $metrics, fn () => null))->report();

    Livewire::test(Messages::class)
        ->assertSee('1,200')
        ->assertSee('300')
        ->assertSee('4 / 0')
        ->assertSee('2 reconnects, 0 takeovers')
        // 1 200 in one minute bucket.
        ->assertSee('20.0/s, peak 20.0/s');
});

it('shows an empty card before anything was recorded', function () {
    Livewire::test(Messages::class)->assertSee('No results');
});

it('lists the replicas, leader first, and shows silent or stopped ones as gone', function () {
    replica('b-standby', ['name' => 'app-2 #11', 'leader' => 'standby', 'received_per_second' => 0.0]);
    replica('a-leader', ['name' => 'app-1 #10', 'leader' => 'leading', 'received_per_second' => 1234.5, 'memory_mb' => 55.4, 'outbox_waiting' => 7, 'last_gap_seconds' => 0.13]);
    replica('c-stopped', ['name' => 'app-1 #9', 'state' => 'stopped', 'leader' => 'stopped'], age: 120);
    replica('d-silent', ['name' => 'app-3 #12'], age: 60);
    replica('e-old', ['name' => 'app-1 #8', 'state' => 'stopped'], age: 3_600);
    Pulse::ingest();

    Livewire::test(Replicas::class)
        ->assertSeeInOrder(['app-1 #10', 'LEADER', 'connected', '1,235', '55', '7', 'app-2 #11', 'standby', 'app-1 #9', 'stopped', 'app-3 #12', 'gone'])
        ->assertDontSee('app-1 #8');

    Livewire::withoutLazyLoading()->test(Replicas::class, ['ignoreAfter' => '2 hours'])->assertSee('app-1 #8');
});

it('lists the routes with their handled, failed and slow runs and the average time', function () {
    $metrics = new MetricsRegistry;
    $metrics->increment('harken_messages_handled_total', 8, ['route' => 'devices/{id}/up']);
    $metrics->increment('harken_handlers_failed_total', 2, ['route' => 'devices/{id}/up']);
    $metrics->increment('harken_handlers_slow_total', 1, ['route' => 'devices/{id}/up']);

    foreach (range(1, 8) as $i) {
        $metrics->observe('harken_handler_seconds', 0.0025, ['route' => 'devices/{id}/up']);
    }

    $metrics->increment('harken_notifications_handled_total', 20, ['channel' => 'orders']);
    (new PulseReporter(app(PulseRecorder::class), $metrics, fn () => null))->report();

    Livewire::test(Handlers::class)
        ->assertSeeInOrder(['NOTIFY orders', '20', 'devices/{id}/up', '8', '2', '1', '2.50'])
        ->set('orderBy', 'failed')
        ->assertViewHas('handlers', fn ($handlers) => $handlers->pluck('route')->all() === ['devices/{id}/up', 'NOTIFY orders']);
});
