<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Properties;

/**
 * Something that can get a message to the broker: the runtime's own
 * connection, or a delegate that hands the message to a running runtime.
 */
interface Publisher
{
    /**
     * @param  Properties|null  $properties  MQTT 5 message properties; dropped on MQTT 3.1.1 connections.
     * @return PromiseInterface<mixed>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface;
}
