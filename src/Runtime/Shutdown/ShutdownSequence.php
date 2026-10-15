<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Shutdown;

use LogicException;
use React\Promise\PromiseInterface;

/**
 * Ordered graceful stop, bounded by a grace period:
 *
 *  1. stop taking new work (unsubscribe / stop reading the outbox and sources)
 *  2. wait for in-flight inline handlers and outbox PUBACKs
 *  3. send DISCONNECT and close the socket
 *  4. stop the loop
 *
 * An inbound QoS 1 message whose handler did not finish is not acknowledged,
 * so the broker redelivers it after restart.
 *
 * Skeleton.
 */
final class ShutdownSequence
{
    public function __construct(public readonly float $graceSeconds = 10.0) {}

    /**
     * @return PromiseInterface<void>
     */
    public function run(): PromiseInterface
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }
}
