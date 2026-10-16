<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Pulse;
use Whatef\Harken\Laravel\Pulse\PulseReporter;
use Whatef\Harken\Observability\MetricsRegistry;
use Whatef\Harken\Tests\Support\FakeLoop;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function replica_status(array $overrides = []): array
{
    return array_replace_recursive([
        'state' => 'running',
        'pid' => 4242,
        'host' => 'app-1',
        'client_id' => 'shop-harken-a1b2c3',
        'started_at' => 1_760_000_000.0,
        'memory_mb' => 30.5,
        'mqtt' => ['state' => 'connected'],
        'leader' => ['state' => 'leading', 'last_gap_seconds' => 0.13],
        'outbox' => ['waiting' => ['harken:outbox:urgent' => 1, 'harken:outbox:normal' => 4]],
    ], $overrides);
}

function bucket_sum(string $type): ?int
{
    $value = DB::table('pulse_aggregates')->where('type', $type)->where('aggregate', 'sum')->where('period', 60)->sum('value');

    return $value === null ? null : (int) $value;
}

beforeEach(function () {
    $this->metrics = new MetricsRegistry;
    $this->status = replica_status();
    $this->reporter = new PulseReporter(app(Pulse::class), $this->metrics, fn () => $this->status, 'default', 15.0);
});

it('records what changed since the last report, summed over the broker connections', function () {
    $this->metrics->increment('harken_messages_received_total', 10, ['connection' => 'main']);
    $this->metrics->increment('harken_messages_received_total', 5, ['connection' => 'leader']);
    $this->metrics->increment('harken_messages_published_total', 3, ['connection' => 'main']);
    $this->metrics->increment('harken_outbox_dropped_total', 1, ['stream' => 'harken:outbox:normal']);
    $this->metrics->increment('harken_leader_takeovers_total');
    $this->metrics->increment('harken_reconnects_total', 0, ['connection' => 'main']);

    $this->reporter->report();

    expect(bucket_sum('harken_received'))->toBe(15)
        ->and(bucket_sum('harken_published'))->toBe(3)
        ->and(bucket_sum('harken_dropped'))->toBe(1)
        ->and(bucket_sum('harken_takeovers'))->toBe(1)
        ->and(bucket_sum('harken_reconnects'))->toBe(0)
        // Totals feed graphs only: no raw entry per interval.
        ->and(DB::table('pulse_entries')->where('type', 'harken_received')->count())->toBe(0);

    $this->metrics->increment('harken_messages_received_total', 7, ['connection' => 'main']);
    $this->reporter->report();

    expect(bucket_sum('harken_received'))->toBe(22)
        ->and(bucket_sum('harken_published'))->toBe(3);
});

it('records handler outcomes per route and NOTIFY channel', function () {
    $route = ['route' => 'devices/{id}/up'];
    $this->metrics->increment('harken_messages_handled_total', 8, $route);
    $this->metrics->increment('harken_handlers_failed_total', 2, $route);
    $this->metrics->increment('harken_handlers_slow_total', 1, $route);

    foreach (range(1, 8) as $i) {
        $this->metrics->observe('harken_handler_seconds', 0.002, $route);
    }

    $this->metrics->increment('harken_notifications_handled_total', 3, ['channel' => 'orders']);
    $this->metrics->increment('harken_messages_handled_total', 0, ['route' => 'idle/topic']);

    $this->reporter->report();

    $entries = DB::table('pulse_entries')->where('type', 'like', 'harken_route_%')->get()
        ->groupBy('key')
        ->map(fn ($rows) => $rows->pluck('value', 'type')->map(fn ($value) => (int) $value)->all());

    expect($entries->all())->toBe([
        'devices/{id}/up' => ['harken_route_handled' => 8, 'harken_route_failed' => 2, 'harken_route_slow' => 1, 'harken_route_time' => 16_000],
        'NOTIFY orders' => ['harken_route_handled' => 3, 'harken_route_failed' => 0, 'harken_route_slow' => 0, 'harken_route_time' => 0],
    ]);

    // Nothing new: a route without traffic in the interval records nothing.
    $this->reporter->report();

    expect(DB::table('pulse_entries')->where('type', 'harken_route_handled')->count())->toBe(2);
});

it('stores the replica status, and marks it stopped when the runtime stops', function () {
    $loop = new FakeLoop;
    $this->reporter->start($loop);
    $this->metrics->increment('harken_messages_received_total', 30, ['connection' => 'main']);

    $loop->advance(1.0);

    $value = DB::table('pulse_values')->where('type', 'harken_replica')->first();
    $status = json_decode($value->value, true);

    expect($value->key)->toBe('shop-harken-a1b2c3')
        ->and($status)->toMatchArray([
            'name' => 'app-1 #4242',
            'connection' => 'default',
            'state' => 'running',
            'mqtt' => 'connected',
            'leader' => 'leading',
            'memory_mb' => 30.5,
            'outbox_waiting' => 5,
            'last_gap_seconds' => 0.13,
        ])
        ->and($status['received_per_second'])->toBeGreaterThan(0);

    $loop->advance(15.0);
    $this->reporter->stop();

    expect(json_decode(DB::table('pulse_values')->where('type', 'harken_replica')->value('value'), true)['state'])->toBe('stopped')
        ->and($loop->pendingTimers())->toBe(0);
});

it('reports nothing about the replica before the runtime exists', function () {
    $reporter = new PulseReporter(app(Pulse::class), $this->metrics, fn () => null);

    $reporter->report();

    expect(DB::table('pulse_values')->count())->toBe(0)
        ->and(bucket_sum('harken_received'))->toBe(0);
});

it('hands errors to the error callback instead of throwing on the loop', function () {
    $errors = [];
    $reporter = new PulseReporter(
        app(Pulse::class),
        $this->metrics,
        fn () => throw new RuntimeException('status failed'),
        onError: function (Throwable $e) use (&$errors) {
            $errors[] = $e->getMessage();
        },
    );

    $reporter->report();

    expect($errors)->toBe(['status failed']);
});
