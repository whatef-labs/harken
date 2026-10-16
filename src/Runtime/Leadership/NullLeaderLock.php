<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * No election: the process always leads. Only for a single replica; a
 * second replica with the same leader client id makes the broker hand the
 * session back and forth.
 */
final class NullLeaderLock implements LeaderLock
{
    public function acquire(string $owner, float $leaseSeconds): PromiseInterface
    {
        return resolve(new LeaseGrant);
    }

    public function renew(string $owner, float $leaseSeconds): PromiseInterface
    {
        return resolve(true);
    }

    public function release(string $owner): PromiseInterface
    {
        return resolve(null);
    }

    public function close(): void {}

    public function name(): string
    {
        return 'none';
    }
}
