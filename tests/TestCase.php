<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Whatef\Harken\Laravel\HarkenServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HarkenServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('harken.routes', __DIR__.'/Fixtures/routes.php');
    }
}
