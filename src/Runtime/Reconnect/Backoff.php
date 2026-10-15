<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Reconnect;

interface Backoff
{
    /**
     * Seconds to wait before the given reconnect attempt (1-based).
     */
    public function delay(int $attempt): float;

    /**
     * Whether another attempt is allowed after `$attempt` failed attempts.
     */
    public function allows(int $attempt): bool;
}
