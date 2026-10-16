<?php

declare(strict_types=1);

/*
 * The end-to-end application over HTTP, for the Pulse dashboard (see
 * demo.php), with the providers and environment of tests/E2E/testbench.yaml:
 *
 *   HARKEN_METRICS_DRIVER=pulse php -S 0.0.0.0:8000 tests/E2E/server.php
 */

use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;

require __DIR__.'/../../vendor/autoload.php';

$config = Config::loadFromYaml(
    workingPath: dirname(__DIR__, 2),
    filename: __DIR__.DIRECTORY_SEPARATOR.'testbench.yaml',
    defaults: ['providers' => [], 'dont-discover' => []],
);

Application::create(basePath: $config['laravel'], options: ['extra' => $config->getExtraAttributes()])
    ->handleRequest(Request::capture());
