<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use React\Promise\PromiseInterface;

/**
 * Something that can get a message to the broker: the runtime's own
 * connection, or a delegate that hands the message to a running runtime.
 */
interface Publisher
{
    /**
     * @return PromiseInterface<mixed>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): PromiseInterface;
}
