<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use Throwable;

interface LeadershipObserver
{
    /**
     * This replica took the lock and starts its leader connection.
     */
    public function acquired(int $term, ?float $vacantSeconds): void;

    /**
     * The leader connection is connected and subscribed.
     *
     * @param  float  $takeoverSeconds  From taking the lock to the SUBACK.
     * @param  float|null  $gapSeconds  From the previous leader's last sign of
     *                                  life to the SUBACK, when the lock can tell.
     */
    public function subscribed(int $term, float $takeoverSeconds, ?float $gapSeconds): void;

    /**
     * This replica stopped leading and stands by again.
     */
    public function lost(int $term, string $reason): void;

    /**
     * A problem the election recovers from, such as a failed lock call.
     */
    public function error(Throwable $error): void;

    /**
     * A problem the election cannot recover from; the runtime should stop.
     */
    public function failed(Throwable $error): void;
}
