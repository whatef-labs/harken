<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Outbox;

use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\PublishRejected;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Runtime\Redis\RedisClient;
use Whatef\Harken\Runtime\Redis\RedisEndpoint;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Redis Streams consumer group outbox.
 *
 * Every replica reads the streams as one consumer group, so each entry is
 * published by one replica: XREADGROUP (streams in priority order) ->
 * publish -> PUBACK -> XACK + XDEL. An entry is removed only after the
 * broker acknowledged it, so delivery is at least once. Entries left pending
 * by a replica that died are taken over with XAUTOCLAIM once they have been
 * idle for $claimIdleMs.
 *
 * Entries of the first stream (when there are several) are published as
 * urgent, ahead of the others still waiting on the connection.
 *
 * At most $batchSize entries are in flight at once, and $maxRate (messages
 * per second) can pace publishing, so a large backlog does not flood the
 * broker. The blocking read has a Redis connection of its own, so it never
 * delays acknowledgements.
 */
final class RedisStreamsOutbox implements Outbox
{
    /** Broker refusals that may succeed on a later attempt. */
    private const array TRANSIENT = [ReasonCode::QuotaExceeded, ReasonCode::PacketIdentifierInUse];

    private ?LoopInterface $loop = null;

    private ?Publisher $publisher = null;

    private ?RedisClient $reader = null;

    private ?RedisClient $acker = null;

    private bool $running = false;

    private bool $reading = false;

    /** @var array<string, true> "stream id" of entries being published. */
    private array $inFlight = [];

    /** @var list<Deferred<null>> */
    private array $drained = [];

    private ?TimerInterface $retryTimer = null;

    private ?TimerInterface $claimTimer = null;

    private float $nextSlot = 0.0;

    /**
     * @param  list<string>  $streams  Stream keys in priority order, including the Redis key prefix.
     */
    public function __construct(
        public readonly RedisEndpoint $endpoint,
        public readonly array $streams,
        public readonly string $group,
        public readonly string $consumer,
        public readonly int $blockMs = 1000,
        public readonly int $batchSize = 100,
        public readonly int $claimIdleMs = 30000,
        public readonly ?float $maxRate = null,
        private readonly ?OutboxObserver $observer = null,
    ) {
        if ($streams === [] || $batchSize < 1 || $blockMs < 1 || $claimIdleMs < 1) {
            throw new InvalidArgumentException('The outbox needs at least one stream and positive batch, block and claim settings.');
        }

        if ($maxRate !== null && $maxRate <= 0) {
            throw new InvalidArgumentException('The outbox rate limit must be greater than zero, or null.');
        }
    }

    public function start(LoopInterface $loop, Publisher $publisher): void
    {
        if ($this->running) {
            return;
        }

        $this->loop = $loop;
        $this->publisher = $publisher;
        $this->reader = new RedisClient($this->endpoint, $loop);
        $this->acker = new RedisClient($this->endpoint, $loop);
        $this->running = true;

        $this->createGroups()->then(function (): void {
            $this->claim();
            $this->read();
        });

        $this->claimTimer = $loop->addPeriodicTimer($this->claimIdleMs / 1000, $this->claim(...));
    }

    /**
     * Stops reading, lets the read in progress and the entries in flight
     * finish (up to the grace period), then closes the Redis connections.
     * Entries that did not finish stay pending for another replica.
     */
    public function stop(float $graceSeconds): PromiseInterface
    {
        $loop = $this->loop;

        if (! $this->running || $loop === null) {
            return resolve(null);
        }

        $this->running = false;

        foreach ([$this->retryTimer, $this->claimTimer] as $timer) {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
        }

        $this->retryTimer = $this->claimTimer = null;
        $deferred = new Deferred;
        $timeout = $loop->addTimer($graceSeconds, fn () => $this->close($deferred));
        $this->whenDrained()->then(function () use ($loop, $timeout, $deferred): void {
            $loop->cancelTimer($timeout);
            $this->close($deferred);
        });

        return $deferred->promise();
    }

    public function inFlightCount(): int
    {
        return count($this->inFlight);
    }

    /**
     * Entries waiting in each stream (XLEN), for status and metrics.
     *
     * @return PromiseInterface<array<string, int>>
     */
    public function lengths(): PromiseInterface
    {
        $acker = $this->acker;

        if ($acker === null) {
            return resolve([]);
        }

        $calls = [];

        foreach ($this->streams as $stream) {
            $calls[] = $acker->call('xlen', $stream)->then(static fn (mixed $length): int => is_numeric($length) ? (int) $length : 0);
        }

        return all($calls)->then(fn (array $lengths): array => array_combine($this->streams, $lengths));
    }

    /**
     * @return PromiseInterface<null>
     */
    private function createGroups(): PromiseInterface
    {
        $acker = $this->acker;

        if ($acker === null) {
            return resolve(null);
        }

        $calls = [];

        foreach ($this->streams as $stream) {
            // Start at 0, not $: entries written before any runtime ran are published too.
            $calls[] = $acker->call('xgroup', 'CREATE', $stream, $this->group, '0', 'MKSTREAM')->then(null, function (Throwable $e): void {
                if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                    throw $e;
                }
            });
        }

        return all($calls)->then(static fn (): null => null, function (Throwable $e): null {
            $this->observer?->error($e);
            $this->retry();

            return null;
        });
    }

    private function read(): void
    {
        $reader = $this->reader;

        if (! $this->running || $this->reading || $reader === null) {
            return;
        }

        $room = $this->batchSize - count($this->inFlight);

        if ($room <= 0) {
            return; // read() runs again when an entry settles
        }

        $this->reading = true;
        $arguments = ['GROUP', $this->group, $this->consumer, 'COUNT', $room, 'BLOCK', $this->blockMs, 'STREAMS', ...$this->streams];

        foreach ($this->streams as $_) {
            $arguments[] = '>';
        }

        $reader->call('xreadgroup', ...$arguments)->then(
            function (mixed $reply): void {
                $this->reading = false;
                $this->accept($reply);
                $this->settledReading();
                $this->read();
            },
            function (Throwable $e): void {
                $this->reading = false;
                $this->settledReading();

                if (! $this->running) {
                    return;
                }

                $this->observer?->error($e);

                if (str_contains($e->getMessage(), 'NOGROUP')) {
                    $this->createGroups()->then(fn () => $this->retry());

                    return;
                }

                $this->retry();
            },
        );
    }

    private function retry(): void
    {
        if (! $this->running || $this->loop === null || $this->retryTimer !== null) {
            return;
        }

        $this->retryTimer = $this->loop->addTimer(1.0, function (): void {
            $this->retryTimer = null;
            $this->read();
        });
    }

    /**
     * Takes over entries another consumer left pending for too long.
     */
    private function claim(): void
    {
        $acker = $this->acker;

        if (! $this->running || $acker === null) {
            return;
        }

        foreach ($this->streams as $stream) {
            $acker->call('xautoclaim', $stream, $this->group, $this->consumer, $this->claimIdleMs, '0-0', 'COUNT', $this->batchSize)->then(
                function (mixed $reply) use ($stream): void {
                    if (is_array($reply) && is_array($reply[1] ?? null)) {
                        $this->accept([[$stream, $reply[1]]]);
                    }
                },
                fn (Throwable $e) => $this->observer?->error($e),
            );
        }
    }

    /**
     * @param  mixed  $reply  XREADGROUP reply: list of [stream, list of [id, fields]], or null on timeout.
     */
    private function accept(mixed $reply): void
    {
        if (! is_array($reply)) {
            return;
        }

        foreach ($reply as $streamEntries) {
            if (! is_array($streamEntries) || ! is_string($streamEntries[0] ?? null) || ! is_array($streamEntries[1] ?? null)) {
                continue;
            }

            foreach ($streamEntries[1] as $entry) {
                if (is_array($entry) && is_string($entry[0] ?? null)) {
                    $this->dispatch($streamEntries[0], $entry[0], is_array($entry[1] ?? null) ? $entry[1] : null);
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $flat  Field/value list; null when the entry was deleted.
     */
    private function dispatch(string $stream, string $id, ?array $flat): void
    {
        $key = "{$stream} {$id}";

        if (isset($this->inFlight[$key])) {
            return; // still being published by this replica; XAUTOCLAIM handed it back
        }

        if ($flat === null) {
            $this->acknowledge($stream, $id);

            return;
        }

        $fields = [];

        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $fields[(string) $flat[$i]] = $flat[$i + 1];
        }

        try {
            $message = OutboxMessage::fromFields($fields);
        } catch (UnexpectedValueException $e) {
            $this->observer?->dropped($stream, $id, null, $e);
            $this->acknowledge($stream, $id);

            return;
        }

        $this->inFlight[$key] = true;
        $urgent = count($this->streams) > 1 && $stream === $this->streams[0];
        $this->paced(fn (): PromiseInterface => $this->send($message, $urgent))->then(
            function () use ($stream, $id, $key, $message): void {
                $this->acknowledge($stream, $id);
                $this->observer?->published($stream, $message, $message->enqueuedAtMs === null ? null : microtime(true) * 1000 - $message->enqueuedAtMs);
                $this->settled($key);
            },
            function (Throwable $e) use ($stream, $id, $key, $message): void {
                if ($e instanceof PublishRejected && ! in_array($e->reasonCode, self::TRANSIENT, true)) {
                    $this->acknowledge($stream, $id);
                    $this->observer?->dropped($stream, $id, $message, $e);
                } else {
                    // Left pending: this or another replica claims it again later.
                    $this->observer?->error($e);
                }

                $this->settled($key);
            },
        );
    }

    /**
     * @return PromiseInterface<mixed>
     */
    private function send(OutboxMessage $message, bool $urgent): PromiseInterface
    {
        if ($this->publisher === null) {
            throw new RuntimeException('The outbox is not started.');
        }

        return $this->publisher->publish($message->topic, $message->payload, $message->qos, $message->retain, $message->properties, $urgent);
    }

    /**
     * Runs $send now, or later when a rate limit is set and the next slot
     * is still ahead.
     *
     * @param  callable(): PromiseInterface<mixed>  $send
     * @return PromiseInterface<mixed>
     */
    private function paced(callable $send): PromiseInterface
    {
        $loop = $this->loop;

        if ($this->maxRate === null || $loop === null) {
            return $send();
        }

        $now = hrtime(true) / 1e9;
        $slot = max($now, $this->nextSlot);
        $this->nextSlot = $slot + 1 / $this->maxRate;

        if ($slot <= $now) {
            return $send();
        }

        $deferred = new Deferred;
        $loop->addTimer($slot - $now, static function () use ($send, $deferred): void {
            try {
                $deferred->resolve($send());
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred->promise();
    }

    private function acknowledge(string $stream, string $id): void
    {
        $acker = $this->acker;

        if ($acker === null) {
            return;
        }

        // Both go out at once, in order, on one connection.
        all([
            $acker->call('xack', $stream, $this->group, $id),
            $acker->call('xdel', $stream, $id),
        ])->then(null, fn (Throwable $e) => $this->observer?->error($e));
    }

    private function settled(string $key): void
    {
        unset($this->inFlight[$key]);
        $this->notifyDrained();
        $this->read();
    }

    private function settledReading(): void
    {
        $this->notifyDrained();
    }

    /**
     * @return PromiseInterface<null>
     */
    private function whenDrained(): PromiseInterface
    {
        $deferred = new Deferred;
        $this->drained[] = $deferred;
        $this->notifyDrained();

        return $deferred->promise();
    }

    private function notifyDrained(): void
    {
        if ($this->reading || $this->inFlight !== [] || $this->drained === []) {
            return;
        }

        $waiters = $this->drained;
        $this->drained = [];

        foreach ($waiters as $waiter) {
            $waiter->resolve(null);
        }
    }

    /**
     * @param  Deferred<null>  $deferred
     */
    private function close(Deferred $deferred): void
    {
        $reader = $this->reader;
        $acker = $this->acker;
        $this->reader = $this->acker = null;
        $reader?->close();

        // end() waits for the last XACK / XDEL replies before closing.
        $acker?->end();
        $deferred->resolve(null);
    }
}
