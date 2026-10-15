<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * The single place where the runtime obtains its event loop.
 *
 * `Loop::get()` picks the best installed backend (ext-uv, ext-ev, ext-event,
 * then stream_select), so switching to libuv needs no code change. Keeping
 * this in one place also leaves room for running on another loop (for
 * example Revolt via an adapter) later.
 */
final class LoopFactory
{
    public static function get(): LoopInterface
    {
        return Loop::get();
    }

    /**
     * Short backend name for logs and `harken:status`, e.g. "StreamSelectLoop".
     */
    public static function backend(?LoopInterface $loop = null): string
    {
        $class = ($loop ?? self::get())::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
