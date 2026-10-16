<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use Throwable;

/**
 * The host of a runtime: logs its life cycle, stores its status for health
 * checks and tells it when a restart was requested. The Laravel bridge
 * implements it on top of the logger and the cache.
 */
interface RuntimeObserver
{
    public function started(Runtime $runtime): void;

    /**
     * A problem the runtime recovers from (a failed timer callback, a lost
     * source connection).
     */
    public function error(Throwable $error): void;

    /**
     * A problem the runtime cannot recover from; it stops with exit code 1.
     */
    public function failed(Throwable $error): void;

    public function stopping(string $reason): void;

    /**
     * A shutdown step did not finish within the grace period, or failed.
     */
    public function shutdownStepAbandoned(string $step, ?Throwable $error): void;

    public function stopped(string $reason, int $exitCode): void;

    /**
     * Called every status interval with Runtime::status().
     *
     * @param  array<string, mixed>  $status
     */
    public function heartbeat(array $status): void;

    /**
     * Whether `harken:restart` (or similar) asked runtimes started at or
     * before $startedAt (Unix time) to stop.
     */
    public function restartRequested(float $startedAt): bool;
}
