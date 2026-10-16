<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Keeps the MQTT session alive and detects a dead peer.
 *
 * A PINGREQ goes out every half keepalive interval. When nothing at all has
 * arrived from the broker by the next tick, the peer is considered dead, so a
 * silent connection is detected within one keepalive interval, well before
 * the broker's own 1.5 x keepalive limit.
 *
 * A single PHP loop cannot send PINGREQ while an inline handler is running,
 * so keepalive cannot be fully independent of handler run time. Pinging at
 * half the interval leaves a full half interval of slack for a slow handler,
 * and handlers over the slow threshold are reported.
 */
final class Keepalive
{
    private ?TimerInterface $timer = null;

    private bool $awaitingResponse = false;

    /** @var Closure(): void */
    private readonly Closure $sendPing;

    /** @var Closure(): void */
    private readonly Closure $onTimeout;

    /**
     * @param  int  $keepAliveSeconds  0 disables keepalive.
     * @param  callable(): void  $sendPing
     * @param  callable(): void  $onTimeout
     */
    public function __construct(
        private readonly LoopInterface $loop,
        public readonly int $keepAliveSeconds,
        callable $sendPing,
        callable $onTimeout,
    ) {
        $this->sendPing = $sendPing(...);
        $this->onTimeout = $onTimeout(...);
    }

    public function start(): void
    {
        $this->stop();

        if ($this->keepAliveSeconds <= 0) {
            return;
        }

        $this->timer = $this->loop->addPeriodicTimer($this->keepAliveSeconds / 2, $this->tick(...));
    }

    /**
     * Any inbound packet proves the connection is alive.
     */
    public function received(): void
    {
        $this->awaitingResponse = false;
    }

    public function stop(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->awaitingResponse = false;
    }

    private function tick(): void
    {
        if ($this->awaitingResponse) {
            $this->stop();
            ($this->onTimeout)();

            return;
        }

        $this->awaitingResponse = true;
        ($this->sendPing)();
    }
}
