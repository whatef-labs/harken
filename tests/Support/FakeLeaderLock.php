<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Support;

use React\Promise\PromiseInterface;
use RuntimeException;
use Whatef\Harken\Runtime\Leadership\LeaderLock;
use Whatef\Harken\Runtime\Leadership\LeaseGrant;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * An in-memory leader lock on a FakeLoop clock. Several instances that share
 * one $store compete like replicas sharing a Redis server.
 */
final class FakeLeaderLock implements LeaderLock
{
    /** Lock calls fail (backend unreachable). */
    public bool $unreachable = false;

    public int $calls = 0;

    /**
     * @param  object{holder: ?string, expiresAt: float, seenAt: ?float}  $store
     */
    public function __construct(private readonly FakeLoop $loop, public readonly object $store) {}

    /**
     * @return object{holder: ?string, expiresAt: float, seenAt: ?float}
     */
    public static function store(): object
    {
        return (object) ['holder' => null, 'expiresAt' => 0.0, 'seenAt' => null];
    }

    public function acquire(string $owner, float $leaseSeconds): PromiseInterface
    {
        $this->calls++;

        if ($this->unreachable) {
            return reject(new RuntimeException('lock backend unreachable'));
        }

        $now = $this->loop->now();

        if ($this->store->holder !== null && $this->store->expiresAt > $now) {
            return resolve(null);
        }

        $vacant = $this->store->seenAt === null ? null : $now - $this->store->seenAt;
        $this->store->holder = $owner;
        $this->store->expiresAt = $now + $leaseSeconds;
        $this->store->seenAt = $now;

        return resolve(new LeaseGrant($vacant));
    }

    public function renew(string $owner, float $leaseSeconds): PromiseInterface
    {
        $this->calls++;

        if ($this->unreachable) {
            return reject(new RuntimeException('lock backend unreachable'));
        }

        $now = $this->loop->now();

        if ($this->store->holder !== $owner || $this->store->expiresAt <= $now) {
            return resolve(false);
        }

        $this->store->expiresAt = $now + $leaseSeconds;
        $this->store->seenAt = $now;

        return resolve(true);
    }

    public function release(string $owner): PromiseInterface
    {
        $this->calls++;

        if ($this->store->holder === $owner) {
            $this->store->holder = null;
            $this->store->expiresAt = 0.0;
            $this->store->seenAt = $this->loop->now();
        }

        return resolve(null);
    }

    public function close(): void {}

    public function name(): string
    {
        return 'fake';
    }
}
