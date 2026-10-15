<?php

declare(strict_types=1);

use React\EventLoop\StreamSelectLoop;
use Whatef\Harken\Runtime\Timers\Scheduler;

it('runs callbacks periodically on the loop', function () {
    $loop = new StreamSelectLoop;
    $scheduler = new Scheduler;
    $runs = 0;
    $scheduler->every(0.01, function () use (&$runs) {
        $runs++;
    });

    $scheduler->start($loop, fn () => null);
    $loop->addTimer(0.065, fn () => $loop->stop());
    $loop->run();

    expect($runs)->toBeGreaterThanOrEqual(4);
});

it('reports failures and keeps the schedule', function () {
    $loop = new StreamSelectLoop;
    $scheduler = new Scheduler;
    $errors = [];
    $scheduler->every(0.01, fn () => throw new RuntimeException('tick failed'));

    $scheduler->start($loop, function (Throwable $e) use (&$errors) {
        $errors[] = $e->getMessage();
    });
    $loop->addTimer(0.045, fn () => $loop->stop());
    $loop->run();

    expect(count($errors))->toBeGreaterThanOrEqual(3)
        ->and(array_unique($errors))->toBe(['tick failed']);
});

it('stops all timers', function () {
    $loop = new StreamSelectLoop;
    $scheduler = new Scheduler;
    $runs = 0;
    $scheduler->every(0.01, function () use (&$runs) {
        $runs++;
    });

    $scheduler->start($loop, fn () => null);
    $scheduler->stop();
    $loop->addTimer(0.04, fn () => $loop->stop());
    $loop->run();

    expect($runs)->toBe(0)->and($scheduler->count())->toBe(1);
});

it('rejects non-positive intervals', function () {
    (new Scheduler)->every(0, fn () => null);
})->throws(InvalidArgumentException::class);
