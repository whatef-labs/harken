<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use React\Promise\PromiseInterface;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Topic;

use function React\Promise\resolve;

/**
 * Broker-free stand-in installed by Harken::fake(). Records publishes and
 * feeds simulated inbound messages through the real route table.
 */
class HarkenFake extends HarkenManager
{
    /** @var list<array{topic: string, payload: string, qos: int, retain: bool, properties: Properties}> */
    private array $published = [];

    public function __construct(HarkenManager $manager, private readonly HandlerInvoker $invoker)
    {
        parent::__construct($manager->router, $manager->scheduler, $manager->publisher, $manager->routesPath, $manager->notifications);

        $this->routesLoaded = $manager->routesLoaded;
    }

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface
    {
        Topic::assertPublishable($topic, $qos);

        $this->published[] = ['topic' => $topic, 'payload' => $payload, 'qos' => $qos, 'retain' => $retain, 'properties' => $properties ?? new Properties];

        return resolve(null);
    }

    public function usePublisher(Publisher $publisher): void
    {
        // Publishing is always recorded while faked.
    }

    /**
     * Delivers a message as if it came from the broker. Inline handlers run
     * now and their exceptions reach the caller; queued routes dispatch
     * their job. Topics without a route are ignored, as in the runtime.
     */
    public function simulate(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): void
    {
        $match = $this->router()->match($topic);

        if ($match === null) {
            return;
        }

        $this->invoker->invoke($match->route, new Message($topic, $payload, $qos, $retain, $match->params, $properties ?? new Properties));
    }

    /**
     * @param  (callable(string $payload, int $qos, bool $retain, Properties $properties): bool)|null  $callback
     */
    public function assertPublished(string $topic, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->published($topic, $callback),
            "The expected [{$topic}] message was not published."
        );
    }

    /**
     * @param  (callable(string $payload, int $qos, bool $retain, Properties $properties): bool)|null  $callback
     */
    public function assertNotPublished(string $topic, ?callable $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->published($topic, $callback),
            "The unexpected [{$topic}] message was published."
        );
    }

    public function assertNothingPublished(): void
    {
        $topics = implode(', ', array_unique(array_column($this->published, 'topic')));

        PHPUnit::assertEmpty($this->published, "Messages were published unexpectedly: [{$topics}].");
    }

    /**
     * @param  (callable(string $payload, int $qos, bool $retain, Properties $properties): bool)|null  $callback
     * @return list<array{topic: string, payload: string, qos: int, retain: bool, properties: Properties}>
     */
    public function published(string $topic, ?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->published,
            static fn (array $message): bool => $message['topic'] === $topic
                && ($callback === null || $callback($message['payload'], $message['qos'], $message['retain'], $message['properties'])),
        ));
    }
}
