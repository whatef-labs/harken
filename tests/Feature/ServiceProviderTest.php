<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Whatef\Harken\Laravel\Console\RestartCommand;
use Whatef\Harken\Laravel\HarkenManager;

it('merges the package configuration', function () {
    expect(config('harken.default'))->toBe('default')
        ->and(config('harken.connections.default.port'))->toBe(1883)
        ->and(config('harken.handlers.slow_threshold_ms'))->toBe(100)
        ->and(config('harken.sources.pgsql.poll_interval_ms'))->toBe(10)
        ->and(config('harken.logging.log_payloads'))->toBeFalse();
});

it('registers the artisan commands', function () {
    expect(array_keys(Artisan::all()))->toContain('harken:start', 'harken:restart', 'harken:status');
});

it('loads routes from routes/harken.php once', function () {
    $manager = app(HarkenManager::class);

    expect($manager->router()->routes())->toHaveCount(3)
        ->and($manager->router()->routes())->toHaveCount(3)
        ->and($manager->scheduler()->count())->toBe(1)
        ->and($manager->router()->subscriptions())->toBe([
            'sensors/+/temperature' => 1,
            '$share/ingest/sensors/+/report' => 1,
            'sensors/+/broken' => 0,
        ]);
});

it('starts without routes when the routes file is missing', function () {
    config()->set('harken.routes', __DIR__.'/does-not-exist.php');
    app()->forgetInstance(HarkenManager::class);

    expect(app(HarkenManager::class)->router()->routes())->toBe([]);
});

it('signals restarts through the cache', function () {
    $this->artisan('harken:restart')->assertSuccessful();

    expect(Cache::get(RestartCommand::CACHE_KEY))->toBeFloat();
});

it('fails to start on an unknown connection', function () {
    $this->artisan('harken:start', ['--connection' => 'missing'])
        ->expectsOutputToContain('Harken connection [missing] is not configured.')
        ->assertFailed();
});

it('fails to start on an invalid connection', function () {
    config()->set('harken.connections.default.host', '');

    $this->artisan('harken:start')
        ->expectsOutputToContain('needs a host')
        ->assertFailed();
});
