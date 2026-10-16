<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Outbox;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Whatef\Harken\Runtime\Publisher;

/**
 * Consumes publish requests made outside the runtime and sends them on the
 * runtime's connection, at least once.
 */
interface Outbox
{
    public function start(LoopInterface $loop, Publisher $publisher): void;

    /**
     * Stops taking new entries and resolves once in-flight entries are
     * acknowledged or the grace period ends.
     *
     * @return PromiseInterface<null>
     */
    public function stop(float $graceSeconds): PromiseInterface;
}
