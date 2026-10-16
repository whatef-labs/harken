<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\LoopFactory;
use Whatef\Harken\Tests\Pulse\PulseTestCase;
use Whatef\Harken\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

// Integration tests run on the loop the runtime uses. On ext-uv its timers
// count from when they are added, not from libuv's clock as of the last loop
// iteration, which stands still while a test runs blocking code.
pest()->group('integration')->beforeEach(function (): void {
    LoopFactory::get();
})->in('Integration');

pest()->extend(PulseTestCase::class)->in('Pulse');
