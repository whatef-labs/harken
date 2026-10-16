<?php

declare(strict_types=1);

use React\EventLoop\ExtUvLoop;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use Whatef\Harken\Runtime\ClockRefreshingLoop;
use Whatef\Harken\Runtime\LoopFactory;
use Whatef\Harken\Tests\Support\FakeLoop;

it('refreshes the clock before adding a timer and passes everything else through', function () {
    $inner = new FakeLoop;
    $calls = [];
    $loop = new ClockRefreshingLoop($inner, function () use (&$calls, $inner): void {
        $calls[] = 'refresh with '.$inner->pendingTimers().' timers';
    });

    $once = $loop->addTimer(1.0, function () use (&$calls): void {
        $calls[] = 'once';
    });
    $periodic = $loop->addPeriodicTimer(0.5, function () use (&$calls): void {
        $calls[] = 'periodic';
    });
    $loop->futureTick(function () use (&$calls): void {
        $calls[] = 'tick';
    });

    expect($calls)->toBe(['refresh with 0 timers', 'refresh with 1 timers'])
        ->and($inner->pendingTimers())->toBe(2);

    $inner->advance(1.0);
    $loop->cancelTimer($periodic);
    $inner->advance(1.0);

    expect(array_slice($calls, 2))->toBe(['tick', 'periodic', 'once', 'periodic'])
        ->and($inner->pendingTimers())->toBe(0)
        ->and($once)->not->toBeNull();
});

it('leaves loops other than ExtUvLoop as they are', function () {
    $loop = new StreamSelectLoop;

    expect(LoopFactory::accurate($loop))->toBe($loop)
        ->and(LoopFactory::backend($loop))->toBe('StreamSelectLoop')
        ->and(LoopFactory::backend(new ClockRefreshingLoop(new FakeLoop, static fn () => null)))->toBe('FakeLoop');
});

it('hands out the loop Loop::get() returns afterwards', function () {
    $loop = LoopFactory::get();

    expect(Loop::get())->toBe($loop)
        ->and(LoopFactory::get())->toBe($loop);
});

it('gives a timer added after a blocking callback its full interval on ext-uv', function () {
    $loop = LoopFactory::accurate(new ExtUvLoop);
    $elapsed = null;

    $loop->futureTick(function () use ($loop, &$elapsed): void {
        usleep(300_000); // a handler blocking the loop
        $added = microtime(true);
        $loop->addTimer(0.2, function () use ($added, &$elapsed): void {
            $elapsed = microtime(true) - $added;
        });
        // Other work wakes the loop at once, as traffic would.
        $loop->futureTick(static fn () => null);
    });
    $loop->run();

    expect($loop)->toBeInstanceOf(ClockRefreshingLoop::class)
        ->and($elapsed)->toBeGreaterThanOrEqual(0.19);
})->skip(! extension_loaded('uv'), 'needs ext-uv');
