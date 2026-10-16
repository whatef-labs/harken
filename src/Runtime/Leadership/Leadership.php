<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

use Closure;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionClosed;

use function React\Promise\resolve;

/**
 * Elects one replica to receive the leader routes.
 *
 * Every replica tries to take the lock every $retryInterval seconds. The
 * holder renews the lease every third of it and opens the leader connection:
 * a fixed client id with a persistent session, so the broker keeps queuing
 * QoS 1 messages while the leader changes. The leader stops receiving as
 * soon as it cannot prove it still holds the lease:
 *
 * - the connection guard fails once the lease, counted from when the last
 *   successful acquire or renew was sent, has run out, so a stalled leader
 *   closes the connection before it handles another message;
 * - a refused renew, or the broker handing the session to another client,
 *   makes it step down and stand by again.
 *
 * A graceful stop disconnects the leader connection and releases the lock
 * at once, so a standby replica takes over within one retry interval.
 */
final class Leadership
{
    private LeadershipState $state = LeadershipState::Idle;

    private string $owner;

    private int $term = 0;

    private float $validUntil = 0.0;

    private bool $renewing = false;

    private ?Connection $connection = null;

    private ?TimerInterface $retryTimer = null;

    private ?TimerInterface $renewTimer = null;

    private ?TimerInterface $expiryTimer = null;

    /** @var Closure(): Connection */
    private readonly Closure $connect;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  callable(): Connection  $connect  Creates the leader connection (not yet connected).
     * @param  (callable(): float)|null  $clock  Monotonic seconds; injected for tests.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        public readonly LeaderLock $lock,
        callable $connect,
        private readonly LeadershipObserver $observer,
        public readonly float $leaseSeconds = 5.0,
        public readonly float $retryInterval = 0.25,
        ?callable $clock = null,
    ) {
        if ($leaseSeconds <= 0 || $retryInterval <= 0) {
            throw new InvalidArgumentException('The lease and the retry interval must be greater than zero.');
        }

        $this->connect = $connect(...);
        $this->clock = $clock === null ? static fn (): float => hrtime(true) / 1e9 : $clock(...);
        $this->owner = self::token();
    }

    public function state(): LeadershipState
    {
        return $this->state;
    }

    public function isLeader(): bool
    {
        return $this->state === LeadershipState::Leading;
    }

    /**
     * How many times this replica has become leader.
     */
    public function terms(): int
    {
        return $this->term;
    }

    public function connection(): ?Connection
    {
        return $this->connection;
    }

    public function start(): void
    {
        if ($this->state !== LeadershipState::Idle) {
            return;
        }

        $this->state = LeadershipState::Standby;
        $this->attempt();
    }

    /**
     * Stops leading or waiting: disconnects the leader connection, then
     * releases the lock so another replica can take over right away.
     *
     * @return PromiseInterface<null>
     */
    public function stop(): PromiseInterface
    {
        if ($this->state === LeadershipState::Stopped || $this->state === LeadershipState::Idle) {
            $this->state = LeadershipState::Stopped;

            return resolve(null);
        }

        $wasLeading = $this->state === LeadershipState::Leading;
        $this->state = LeadershipState::Stopped;
        $this->cancelTimers();

        $connection = $this->connection;
        $this->connection = null;
        $owner = $this->owner;

        return ($connection?->disconnect() ?? resolve(null))
            ->then(fn () => $wasLeading ? $this->lock->release($owner) : null)
            ->then(static fn (): null => null, function (Throwable $e): null {
                $this->observer->error($e);

                return null;
            })
            ->then(function (): null {
                $this->lock->close();

                return null;
            });
    }

    private function attempt(): void
    {
        $this->retryTimer = null;

        if ($this->state !== LeadershipState::Standby) {
            return;
        }

        $owner = $this->owner;
        $sentAt = $this->now();

        $this->lock->acquire($owner, $this->leaseSeconds)->then(
            function (?LeaseGrant $grant) use ($owner, $sentAt): void {
                if ($this->state !== LeadershipState::Standby || $owner !== $this->owner) {
                    if ($grant !== null) {
                        $this->lock->release($owner);
                    }

                    return;
                }

                $grant === null ? $this->retryLater() : $this->lead($grant, $sentAt);
            },
            function (Throwable $e): void {
                if ($this->state === LeadershipState::Standby) {
                    $this->observer->error($e);
                    $this->retryLater();
                }
            },
        );
    }

    private function lead(LeaseGrant $grant, float $sentAt): void
    {
        $this->state = LeadershipState::Leading;
        $term = ++$this->term;
        $this->extend($sentAt);
        $this->renewTimer = $this->loop->addPeriodicTimer($this->leaseSeconds / 3, $this->renew(...));
        $this->observer->acquired($term, $grant->vacantSeconds);

        if ($this->state !== LeadershipState::Leading || $term !== $this->term) {
            return;
        }

        $connection = ($this->connect)();
        $this->connection = $connection;
        $connection->guard(fn (): bool => $this->state === LeadershipState::Leading && $this->term === $term && $this->now() < $this->validUntil);
        $connection->onClose(function (?Throwable $reason) use ($connection): void {
            $this->connectionClosed($connection, $reason);
        });

        $acquiredAt = $this->now();
        $connection->connect()->then(function () use ($connection, $term, $grant, $acquiredAt): void {
            if ($connection !== $this->connection) {
                return;
            }

            $takeover = $this->now() - $acquiredAt;
            $this->observer->subscribed($term, $takeover, $grant->vacantSeconds === null ? null : $grant->vacantSeconds + $takeover);
        }, static function (): void {
            // Failures arrive through onClose.
        });
    }

    private function renew(): void
    {
        if ($this->state !== LeadershipState::Leading || $this->renewing) {
            return;
        }

        $this->renewing = true;
        $owner = $this->owner;
        $sentAt = $this->now();

        $this->lock->renew($owner, $this->leaseSeconds)->then(
            function (bool $held) use ($owner, $sentAt): void {
                $this->renewing = false;

                if ($this->state !== LeadershipState::Leading || $owner !== $this->owner) {
                    return;
                }

                $held ? $this->extend($sentAt) : $this->stepDown('another replica holds the lock');
            },
            function (Throwable $e): void {
                $this->renewing = false;
                // Keep trying on the next tick; the expiry timer ends the term if the lease runs out first.
                $this->observer->error($e);
            },
        );
    }

    private function extend(float $sentAt): void
    {
        $this->validUntil = $sentAt + $this->leaseSeconds;

        if ($this->expiryTimer !== null) {
            $this->loop->cancelTimer($this->expiryTimer);
        }

        $this->expiryTimer = $this->loop->addTimer(max(0.0, $this->validUntil - $this->now()), function (): void {
            $this->expiryTimer = null;
            $this->stepDown('the lease ran out before it could be renewed');
        });
    }

    private function connectionClosed(Connection $connection, ?Throwable $reason): void
    {
        if ($connection !== $this->connection || $this->state !== LeadershipState::Leading) {
            return;
        }

        if ($reason instanceof ConnectionClosed && $reason->isSessionTakenOver()) {
            if ($this->lock instanceof NullLeaderLock) {
                $this->connection = null;
                $this->stepDown('another client connected with the leader client id', release: true);
                $this->observer->failed(new RuntimeException(
                    'Another client connected with the leader client id. With the "none" leader lock only one replica may run; use the redis or pgsql lock for several.',
                    0,
                    $reason,
                ));

                return;
            }

            $this->connection = null;
            $this->stepDown('another client took over the leader session', release: true);

            return;
        }

        $this->connection = null;

        if ($reason instanceof ConnectionClosed && $reason->guarded) {
            $this->stepDown('the lease ran out before it could be renewed');

            return;
        }

        $this->stepDown('the leader connection failed', release: true);
        $this->observer->failed($reason ?? new RuntimeException('The leader connection closed.'));
    }

    private function stepDown(string $reason, bool $release = false): void
    {
        if ($this->state !== LeadershipState::Leading) {
            return;
        }

        $this->state = LeadershipState::Standby;
        $this->cancelTimers();

        $connection = $this->connection;
        $this->connection = null;
        $connection?->disconnect();

        $owner = $this->owner;
        $this->owner = self::token();

        if ($release) {
            $this->lock->release($owner)->then(null, fn (Throwable $e) => $this->observer->error($e));
        }

        $this->observer->lost($this->term, $reason);

        if ($this->state === LeadershipState::Standby) {
            $this->retryLater();
        }
    }

    private function retryLater(): void
    {
        $this->retryTimer = $this->loop->addTimer($this->retryInterval, $this->attempt(...));
    }

    private function cancelTimers(): void
    {
        foreach ([$this->retryTimer, $this->renewTimer, $this->expiryTimer] as $timer) {
            if ($timer !== null) {
                $this->loop->cancelTimer($timer);
            }
        }

        $this->retryTimer = $this->renewTimer = $this->expiryTimer = null;
        $this->renewing = false;
    }

    private function now(): float
    {
        return ($this->clock)();
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
