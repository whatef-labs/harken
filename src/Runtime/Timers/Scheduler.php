<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Timers;

use Closure;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;

/**
 * Periodic callbacks registered before the runtime starts (`Harken::every()`)
 * and run on the event loop. A failing callback is reported and keeps its
 * schedule; it never stops the loop.
 */
final class Scheduler
{
    /** @var list<array{float, Closure(): mixed}> */
    private array $tasks = [];

    /** @var list<TimerInterface> */
    private array $timers = [];

    private ?LoopInterface $loop = null;

    /**
     * @param  callable(): mixed  $callback
     */
    public function every(int|float $seconds, callable $callback): void
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The interval must be greater than zero seconds.');
        }

        $this->tasks[] = [(float) $seconds, $callback(...)];
    }

    public function count(): int
    {
        return count($this->tasks);
    }

    /**
     * @param  callable(Throwable): void  $onError
     */
    public function start(LoopInterface $loop, callable $onError): void
    {
        $this->stop();
        $this->loop = $loop;

        foreach ($this->tasks as [$interval, $callback]) {
            $this->timers[] = $loop->addPeriodicTimer($interval, static function () use ($callback, $onError): void {
                try {
                    $callback();
                } catch (Throwable $e) {
                    $onError($e);
                }
            });
        }
    }

    public function stop(): void
    {
        foreach ($this->timers as $timer) {
            $this->loop?->cancelTimer($timer);
        }

        $this->timers = [];
    }
}
