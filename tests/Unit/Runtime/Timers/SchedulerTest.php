<?php

declare(strict_types=1);

use React\EventLoop\StreamSelectLoop;
use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Timers\Scheduler;
use Whatef\Harken\Tests\Support\FakeLoop;

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

it('runs tasks on the leader by default, or on every replica', function () {
    $scheduler = new Scheduler;
    $sweep = $scheduler->every(5, fn () => null);
    $refresh = $scheduler->every(60, fn () => null)->broadcast();

    expect($sweep->getDistribution())->toBe(Distribution::Leader)
        ->and($refresh->getDistribution())->toBe(Distribution::Broadcast)
        ->and($sweep->interval)->toBe(5.0)
        ->and($scheduler->only(Distribution::Leader)->tasks())->toBe([$sweep])
        ->and($scheduler->only(Distribution::Broadcast)->tasks())->toBe([$refresh])
        ->and($refresh->leader()->getDistribution())->toBe(Distribution::Leader);
});

it('skips runs while its guard says no', function () {
    $loop = new FakeLoop;
    $scheduler = new Scheduler;
    $runs = 0;
    $allowed = false;
    $scheduler->every(1, function () use (&$runs) {
        $runs++;
    });

    $scheduler->start($loop, fn () => null, function () use (&$allowed) {
        return $allowed;
    });
    $loop->advance(2.0);
    $allowed = true;
    $loop->advance(2.0);

    expect($runs)->toBe(2);
});
