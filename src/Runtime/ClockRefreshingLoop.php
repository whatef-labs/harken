<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * A loop that brings its backend's clock up to date before it adds a timer.
 *
 * ExtUvLoop schedules timers from libuv's cached loop time, which only moves
 * between loop iterations. A timer added after a callback that blocked
 * (a slow inline handler) is measured from before the block, so once the
 * loop wakes up for other work it fires early by the time the callback
 * took: a 5 s timeout added after a 6 s handler fires at once. Refreshing
 * the clock first gives the timer its full interval, as StreamSelectLoop
 * does by reading the time when a timer is added.
 *
 * @internal Created by LoopFactory.
 */
final readonly class ClockRefreshingLoop implements LoopInterface
{
    /**
     * @param  Closure(): void  $refresh
     */
    public function __construct(
        public LoopInterface $inner,
        private Closure $refresh,
    ) {}

    public function addReadStream($stream, $listener): void
    {
        $this->inner->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->inner->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
        $this->inner->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        $this->inner->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        ($this->refresh)();

        return $this->inner->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        ($this->refresh)();

        return $this->inner->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->inner->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        $this->inner->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        $this->inner->removeSignal($signal, $listener);
    }

    public function run(): void
    {
        $this->inner->run();
    }

    public function stop(): void
    {
        $this->inner->stop();
    }
}
