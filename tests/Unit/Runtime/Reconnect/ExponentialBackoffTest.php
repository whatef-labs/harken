<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;

it('grows exponentially up to the maximum without jitter', function () {
    $backoff = new ExponentialBackoff(initial: 1.0, max: 60.0, multiplier: 2.0, jitter: 0.0);

    expect(array_map($backoff->delay(...), range(1, 8)))
        ->toBe([1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 60.0, 60.0]);
});

it('subtracts a random share of the delay as jitter', function () {
    $low = new ExponentialBackoff(initial: 1.0, max: 60.0, jitter: 0.5, random: fn () => 0.0);
    $high = new ExponentialBackoff(initial: 1.0, max: 60.0, jitter: 0.5, random: fn () => 0.999999);

    expect($low->delay(4))->toBe(8.0)
        ->and($high->delay(4))->toEqualWithDelta(4.0, 0.0001);
});

it('never exceeds the maximum and never goes negative', function () {
    $backoff = new ExponentialBackoff(initial: 1.0, max: 10.0, jitter: 1.0);

    foreach (range(1, 200) as $attempt) {
        $delay = $backoff->delay($attempt);
        expect($delay)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(10.0);
    }
});

it('limits attempts when configured', function () {
    $unlimited = new ExponentialBackoff;
    $limited = new ExponentialBackoff(maxAttempts: 3);

    expect($unlimited->allows(10_000))->toBeTrue()
        ->and($limited->allows(2))->toBeTrue()
        ->and($limited->allows(3))->toBeFalse();
});

it('reads the reconnect config block', function () {
    $backoff = ExponentialBackoff::fromArray([
        'initial_delay' => 0.5, 'max_delay' => 30, 'multiplier' => 3, 'jitter' => 0.25, 'max_attempts' => 5,
    ]);

    expect($backoff->initial)->toBe(0.5)
        ->and($backoff->max)->toBe(30.0)
        ->and($backoff->multiplier)->toBe(3.0)
        ->and($backoff->jitter)->toBe(0.25)
        ->and($backoff->maxAttempts)->toBe(5);
});

it('rejects inconsistent settings', function (array $args) {
    new ExponentialBackoff(...$args);
})->with([
    [['initial' => 0.0]],
    [['initial' => 10.0, 'max' => 5.0]],
    [['multiplier' => 0.5]],
    [['jitter' => 1.5]],
    [['maxAttempts' => 0]],
])->throws(InvalidArgumentException::class);
