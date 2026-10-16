<?php

declare(strict_types=1);

use React\EventLoop\ExtUvLoop;
use React\EventLoop\Loop;
use Whatef\Harken\Tests\Pulse\PulseTestCase;
use Whatef\Harken\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

// ExtUvLoop schedules timers from libuv's cached time, which only moves while
// the loop runs. After a test that ran no loop for a while (blocking sockets,
// sleeps), a timeout the next test sets would already be due; bring the time
// up to date first.
pest()->group('integration')->beforeEach(function (): void {
    $loop = Loop::get();

    if ($loop instanceof ExtUvLoop) {
        uv_update_time($loop->getUvLoop());
    }
})->in('Integration');

pest()->extend(PulseTestCase::class)->in('Pulse');
