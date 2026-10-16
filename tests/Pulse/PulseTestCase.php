<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Pulse;

use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;
use Whatef\Harken\Laravel\HarkenServiceProvider;
use Whatef\Harken\Tests\TestCase;

/**
 * An application with Livewire and Laravel Pulse, storing Pulse data in an
 * in-memory SQLite database.
 */
abstract class PulseTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, PulseServiceProvider::class, HarkenServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('harken.metrics.driver', 'pulse');
        $app['config']->set('pulse.ingest.trim.lottery', [0, 1]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/pulse/database/migrations');
    }
}
