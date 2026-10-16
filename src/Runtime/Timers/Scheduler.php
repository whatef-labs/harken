<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Timers;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;
use Whatef\Harken\Runtime\Routing\Distribution;

/**
 * Periodic callbacks registered before the runtime starts (`Harken::every()`)
 * and run on the event loop. A failing callback is reported and keeps its
 * schedule; it never stops the loop.
 */
final class Scheduler
{
    /** @var list<Task> */
    private array $tasks = [];

    /** @var list<TimerInterface> */
    private array $timers = [];

    private ?LoopInterface $loop = null;

    /**
     * @param  callable(): mixed  $callback
     */
    public function every(int|float $seconds, callable $callback): Task
    {
        return $this->tasks[] = new Task($seconds, $callback);
    }

    public function count(): int
    {
        return count($this->tasks);
    }

    /**
     * @return list<Task>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * A scheduler over the tasks with one of the given distributions. The
     * runtime starts the leader tasks only while it leads.
     */
    public function only(Distribution ...$distributions): self
    {
        $subset = new self;

        foreach ($this->tasks as $task) {
            if (in_array($task->getDistribution(), $distributions, true)) {
                $subset->tasks[] = $task;
            }
        }

        return $subset;
    }

    /**
     * @param  callable(Throwable): void  $onError
     * @param  (callable(): bool)|null  $guard  Checked before every run; a run is skipped while it returns false.
     */
    public function start(LoopInterface $loop, callable $onError, ?callable $guard = null): void
    {
        $this->stop();
        $this->loop = $loop;
        $guard = $guard === null ? null : $guard(...);

        foreach ($this->tasks as $task) {
            $callback = $task->callback;
            /** @var Closure(): void $run */
            $run = static function () use ($callback, $onError, $guard): void {
                if ($guard !== null && ! $guard()) {
                    return;
                }

                try {
                    $callback();
                } catch (Throwable $e) {
                    $onError($e);
                }
            };

            $this->timers[] = $loop->addPeriodicTimer($task->interval, $run);
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
