<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Outbox;

use LogicException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Whatef\Harken\Runtime\Publisher;

/**
 * Redis Streams consumer group outbox.
 *
 * Planned flow per entry: XREADGROUP (urgent stream first) -> publish
 * -> PUBACK -> XACK. Entries left pending by a crashed replica are reclaimed
 * with XAUTOCLAIM on start. Uses dedicated async Redis connections so the
 * blocking read never delays other Redis traffic.
 *
 * Skeleton: shaped by spike 03.
 */
final class RedisStreamsOutbox implements Outbox
{
    /**
     * @param  list<string>  $streams  Stream keys in priority order.
     */
    public function __construct(
        public readonly string $redisUri,
        public readonly array $streams,
        public readonly string $group,
        public readonly string $consumer,
        public readonly int $blockMs = 1000,
        public readonly int $batchSize = 100,
        public readonly int $claimIdleMs = 30000,
    ) {}

    public function start(LoopInterface $loop, Publisher $publisher): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }

    public function stop(float $graceSeconds): PromiseInterface
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }
}
