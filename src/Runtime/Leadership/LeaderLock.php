<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use React\Promise\PromiseInterface;

/**
 * A lock with a time-limited lease that at most one replica holds. The
 * holder renews it well before the lease runs out; when the holder stops
 * renewing (crash, network loss), the lock frees itself after the lease.
 *
 * Every call must be non-blocking: it runs on the event loop.
 */
interface LeaderLock
{
    /**
     * Takes the lock when it is free. Resolves with a grant, or null when
     * another owner holds it.
     *
     * @return PromiseInterface<LeaseGrant|null>
     */
    public function acquire(string $owner, float $leaseSeconds): PromiseInterface;

    /**
     * Extends the lease. Resolves with false when the lock is no longer held
     * by this owner.
     *
     * @return PromiseInterface<bool>
     */
    public function renew(string $owner, float $leaseSeconds): PromiseInterface;

    /**
     * Frees the lock at once if this owner holds it, so a waiting replica can
     * take over without waiting for the lease to run out.
     *
     * @return PromiseInterface<null>
     */
    public function release(string $owner): PromiseInterface;

    /**
     * Closes the lock's connections. The lock must not be used afterwards.
     */
    public function close(): void;

    /**
     * Short name for logs and status, e.g. "redis".
     */
    public function name(): string;
}
