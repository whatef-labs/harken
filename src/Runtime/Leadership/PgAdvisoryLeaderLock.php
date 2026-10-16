<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use React\Promise\PromiseInterface;
use Throwable;
use Whatef\Harken\Runtime\Postgres\AsyncPgConnection;

use function React\Promise\resolve;

/**
 * Leader lock on a PostgreSQL session-level advisory lock (PostgreSQL 14+,
 * ext-pgsql).
 *
 * The lock lives as long as the database session that took it. Once taken,
 * the session sets idle_session_timeout to the lease, so the server ends it
 * (and frees the lock) when the holder stops renewing, even if its socket
 * never closes. Renewing is a query on that session; releasing unlocks.
 * PostgreSQL keeps no record of the previous holder, so grants carry no
 * vacancy time.
 */
final class PgAdvisoryLeaderLock implements LeaderLock
{
    public readonly int $lockId;

    private ?string $holder = null;

    public function __construct(
        private readonly AsyncPgConnection $connection,
        public readonly string $key,
    ) {
        $this->lockId = self::lockId($key);
        $connection->onClose(function (): void {
            $this->holder = null;
        });
    }

    /**
     * A stable signed 64-bit lock id for a key.
     */
    public static function lockId(string $key): int
    {
        /** @var array{1: int} $parts */
        $parts = unpack('J', substr(md5($key, true), 0, 8));

        return $parts[1];
    }

    public function acquire(string $owner, float $leaseSeconds): PromiseInterface
    {
        if ($this->holder === $owner) {
            return resolve(new LeaseGrant);
        }

        $timeout = (int) round($leaseSeconds * 1000);

        // Take the lock and arm the idle timeout in one statement.
        return $this->connection->query(
            "SELECT CASE WHEN pg_try_advisory_lock({$this->lockId}) THEN set_config('idle_session_timeout', '{$timeout}', false) END AS locked"
        )->then(function (array $rows) use ($owner): ?LeaseGrant {
            if (($rows[0]['locked'] ?? null) === null) {
                return null;
            }

            $this->holder = $owner;

            return new LeaseGrant;
        });
    }

    public function renew(string $owner, float $leaseSeconds): PromiseInterface
    {
        if ($this->holder !== $owner || ! $this->connection->isConnected()) {
            return resolve(false);
        }

        return $this->connection->query('SELECT 1')->then(
            fn (): bool => $this->holder === $owner,
            function (Throwable $e) use ($owner): bool {
                // The session is gone, and the server released the lock with it.
                if ($this->holder === $owner) {
                    $this->holder = null;
                }

                return false;
            },
        );
    }

    public function release(string $owner): PromiseInterface
    {
        if ($this->holder !== $owner) {
            return resolve(null);
        }

        $this->holder = null;

        return $this->connection->query("SELECT pg_advisory_unlock_all(), set_config('idle_session_timeout', '0', false)")
            ->then(static fn (): null => null, static fn (): null => null);
    }

    public function close(): void
    {
        $this->holder = null;
        $this->connection->close();
    }

    public function name(): string
    {
        return 'pgsql';
    }
}
