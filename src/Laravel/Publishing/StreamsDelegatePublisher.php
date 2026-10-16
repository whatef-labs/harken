<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Publishing;

use LogicException;
use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Publisher;

/**
 * Publishing from outside the runtime: appends the message to the outbox
 * Redis stream (XADD) for a running runtime to send. Resolves once the entry
 * is stored, not when the broker acknowledges it.
 *
 * Skeleton: entry format and priority handling are settled by spike 03.
 */
final readonly class StreamsDelegatePublisher implements Publisher
{
    public function __construct(
        public string $redisConnection,
        public string $stream,
    ) {}

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface
    {
        throw new LogicException('Publishing from outside the Harken runtime is not implemented yet.');
    }
}
