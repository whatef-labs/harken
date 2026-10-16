<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Support;

use LogicException;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use SplObjectStorage;

/**
 * A loop with a manual clock for unit tests: timers fire only when the test
 * calls advance(). Streams and signals are not supported.
 */
final class FakeLoop implements LoopInterface
{
    private float $now = 0.0;

    /** @var SplObjectStorage<TimerInterface, float> Timer => due time. */
    private SplObjectStorage $timers;

    /** @var list<callable> */
    private array $ticks = [];

    public function __construct()
    {
        $this->timers = new SplObjectStorage;
    }

    public function now(): float
    {
        return $this->now;
    }

    /**
     * Moves the clock forward, firing due timers in order (and future ticks).
     */
    public function advance(float $seconds): void
    {
        $target = $this->now + $seconds;
        $this->runTicks();

        while (($next = $this->nextDue()) !== null && $this->timers[$next] <= $target) {
            $this->now = max($this->now, $this->timers[$next]);

            if ($next->isPeriodic()) {
                $this->timers[$next] = $this->now + $next->getInterval();
            } else {
                $this->timers->offsetUnset($next);
            }

            ($next->getCallback())($next);
            $this->runTicks();
        }

        $this->now = $target;
    }

    public function pendingTimers(): int
    {
        return $this->timers->count();
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->timers[$timer] = $this->now + $timer->getInterval();

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->timers[$timer] = $this->now + $timer->getInterval();

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers->offsetUnset($timer);
    }

    public function futureTick($listener): void
    {
        $this->ticks[] = $listener;
    }

    public function addReadStream($stream, $listener): void
    {
        throw new LogicException('FakeLoop does not support streams.');
    }

    public function addWriteStream($stream, $listener): void
    {
        throw new LogicException('FakeLoop does not support streams.');
    }

    public function removeReadStream($stream): void {}

    public function removeWriteStream($stream): void {}

    public function addSignal($signal, $listener): void {}

    public function removeSignal($signal, $listener): void {}

    public function run(): void
    {
        throw new LogicException('FakeLoop is driven with advance().');
    }

    public function stop(): void {}

    private function nextDue(): ?TimerInterface
    {
        $next = null;

        foreach ($this->timers as $timer) {
            if ($next === null || $this->timers[$timer] < $this->timers[$next]) {
                $next = $timer;
            }
        }

        return $next;
    }

    private function runTicks(): void
    {
        while ($this->ticks !== []) {
            $tick = array_shift($this->ticks);
            $tick();
        }
    }
}
