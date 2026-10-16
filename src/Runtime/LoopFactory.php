<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use React\EventLoop\ExtUvLoop;
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
        $loop = Loop::get();
        $accurate = self::accurate($loop);

        if ($accurate !== $loop) {
            // Libraries that ask Loop::get() (sockets, Redis) get the same loop.
            Loop::set($accurate);
        }

        return $accurate;
    }

    /**
     * Wraps ExtUvLoop so its timers count from the time they are added; see
     * ClockRefreshingLoop. Other loops are returned as they are.
     */
    public static function accurate(LoopInterface $loop): LoopInterface
    {
        if (! $loop instanceof ExtUvLoop) {
            return $loop;
        }

        return new ClockRefreshingLoop($loop, static function () use ($loop): void {
            uv_update_time($loop->getUvLoop());
        });
    }

    /**
     * Short backend name for logs and `harken:status`, e.g. "StreamSelectLoop".
     */
    public static function backend(?LoopInterface $loop = null): string
    {
        $loop ??= self::get();
        $class = ($loop instanceof ClockRefreshingLoop ? $loop->inner : $loop)::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
