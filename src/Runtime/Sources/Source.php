<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use React\EventLoop\LoopInterface;

/**
 * An external event stream attached to the runtime's loop (for example
 * PostgreSQL LISTEN/NOTIFY), so it is served by the same process without
 * blocking MQTT traffic.
 */
interface Source
{
    public function name(): string;

    public function start(LoopInterface $loop): void;

    public function stop(): void;
}
