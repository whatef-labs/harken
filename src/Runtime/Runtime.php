<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use LogicException;
use React\EventLoop\LoopInterface;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Outbox\Outbox;
use Whatef\Harken\Runtime\Routing\Dispatcher;
use Whatef\Harken\Runtime\Routing\Router;
use Whatef\Harken\Runtime\Shutdown\ShutdownSequence;
use Whatef\Harken\Runtime\Sources\Source;
use Whatef\Harken\Runtime\Timers\Scheduler;

/**
 * Wires the connection, router, outbox, sources and timers onto one loop and
 * runs until stopped. Framework-agnostic; the Laravel command builds it.
 *
 * Skeleton.
 */
final class Runtime
{
    /**
     * @param  list<Source>  $sources
     */
    public function __construct(
        public readonly LoopInterface $loop,
        public readonly Connection $connection,
        public readonly Router $router,
        public readonly Dispatcher $dispatcher,
        public readonly Scheduler $scheduler,
        public readonly ShutdownSequence $shutdown,
        public readonly ?Outbox $outbox = null,
        public readonly array $sources = [],
    ) {}

    /**
     * Blocks until the runtime stops. Returns the process exit code.
     */
    public function run(): int
    {
        throw new LogicException('The Harken runtime loop is not implemented yet.');
    }

    /**
     * Requests a graceful stop (signal, restart flag, memory or time limit).
     */
    public function stop(string $reason): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }
}
