<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Timers;

use Closure;
use InvalidArgumentException;
use Whatef\Harken\Runtime\Routing\Distribution;

/**
 * A periodic callback registered with Harken::every(), configured fluently:
 *
 *     Harken::every(5, fn () => $sweeper->sweep());               // leader only
 *     Harken::every(60, fn () => $cache->refresh())->broadcast(); // every replica
 *
 * By default only the elected leader replica runs it, so work such as
 * sweeping pending rows is done once, not once per replica.
 */
final class Task
{
    public readonly float $interval;

    private Distribution $distribution = Distribution::Leader;

    /** @var Closure(): mixed */
    public readonly Closure $callback;

    /**
     * @param  callable(): mixed  $callback
     */
    public function __construct(int|float $seconds, callable $callback)
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The interval must be greater than zero seconds.');
        }

        $this->interval = (float) $seconds;
        $this->callback = $callback(...);
    }

    /**
     * Only the elected leader replica runs the task. This is the default.
     */
    public function leader(): static
    {
        $this->distribution = Distribution::Leader;

        return $this;
    }

    /**
     * Every replica runs the task.
     */
    public function broadcast(): static
    {
        $this->distribution = Distribution::Broadcast;

        return $this;
    }

    public function getDistribution(): Distribution
    {
        return $this->distribution;
    }
}
