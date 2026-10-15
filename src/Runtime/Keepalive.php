<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use LogicException;
use React\EventLoop\LoopInterface;

/**
 * Keeps the MQTT session alive and detects a dead peer.
 *
 * A single PHP loop cannot send PINGREQ while an inline handler is running,
 * so keepalive cannot be fully independent of handler run time. Harken pings
 * at half the keepalive interval, which leaves a full half interval of slack
 * for a slow handler, and reports handlers over the slow threshold.
 *
 * Skeleton: filled in together with Connection after spike 01.
 */
final class Keepalive
{
    /**
     * @param  callable(): void  $sendPing
     * @param  callable(): void  $onTimeout
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $keepAliveSeconds,
        private readonly mixed $sendPing,
        private readonly mixed $onTimeout,
    ) {}

    public function start(): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }

    /**
     * Any inbound packet proves the connection is alive.
     */
    public function received(): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }

    public function stop(): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }
}
