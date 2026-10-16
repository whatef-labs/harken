<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Properties;

/**
 * Returned by Harken::urgent(). Same calls as the facade, sent ahead of
 * messages that are not urgent:
 *
 *     Harken::urgent()->publish('cmd/door/1', $payload, qos: 1);
 *
 * Outside the runtime the message goes to the urgent outbox stream, which
 * every runtime drains before the normal one. Inside the runtime it goes to
 * the front of the connection's queue.
 */
final readonly class UrgentPublisher
{
    public function __construct(private HarkenManager $manager) {}

    /**
     * @param  Properties|null  $properties  MQTT 5 message properties; dropped on MQTT 3.1.1 connections.
     * @return PromiseInterface<mixed>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface
    {
        return $this->manager->send($topic, $payload, $qos, $retain, $properties, true);
    }

    /**
     * Removes the retained message of a topic (empty payload, retain flag).
     *
     * @return PromiseInterface<mixed>
     */
    public function clearRetained(string $topic): PromiseInterface
    {
        return $this->publish($topic, '', 1, true);
    }
}
