<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Routing\Router;
use Whatef\Harken\Runtime\Sources\NotificationRoute;
use Whatef\Harken\Runtime\Sources\NotificationRouter;
use Whatef\Harken\Runtime\Timers\Scheduler;
use Whatef\Harken\Runtime\Timers\Task;
use Whatef\Harken\Runtime\Topic;

/**
 * Root of the Harken facade.
 *
 * Outside the runtime, publish() goes through the outbox delegate. When
 * `harken:start` runs, the publisher is swapped for the live connection, so
 * the same call publishes directly from handlers and timers.
 */
class HarkenManager
{
    protected bool $routesLoaded = false;

    public function __construct(
        protected Router $router,
        protected Scheduler $scheduler,
        protected Publisher $publisher,
        protected ?string $routesPath = null,
        protected NotificationRouter $notifications = new NotificationRouter,
    ) {}

    /**
     * @param  class-string|callable  $handler
     */
    public function topic(string $pattern, string|callable $handler): Route
    {
        return $this->router->add(new Route($pattern, $handler));
    }

    /**
     * @param  Properties|null  $properties  MQTT 5 message properties; dropped on MQTT 3.1.1 connections.
     * @return PromiseInterface<mixed>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface
    {
        return $this->send($topic, $payload, $qos, $retain, $properties, false);
    }

    /**
     * Publishing that goes ahead of messages that are not urgent and still
     * waiting: the urgent outbox stream outside the runtime, the front of the
     * connection's queue inside it.
     */
    public function urgent(): UrgentPublisher
    {
        return new UrgentPublisher($this);
    }

    /**
     * @internal Used by publish() and UrgentPublisher.
     *
     * @return PromiseInterface<mixed>
     */
    public function send(string $topic, string $payload, int $qos, bool $retain, ?Properties $properties, bool $urgent): PromiseInterface
    {
        Topic::assertPublishable($topic, $qos);

        return $this->publisher->publish($topic, $payload, $qos, $retain, $properties, $urgent);
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

    /**
     * Handles PostgreSQL NOTIFY on a channel. Only the leader replica
     * listens unless the route calls ->broadcast().
     *
     * @param  class-string|callable  $handler
     */
    public function listen(string $channel, string|callable $handler): NotificationRoute
    {
        return $this->notifications->add(new NotificationRoute($channel, $handler));
    }

    /**
     * Runs a callback periodically. Only the leader replica runs it unless
     * the task calls ->broadcast().
     *
     * @param  callable(): mixed  $callback
     */
    public function every(int|float $seconds, callable $callback): Task
    {
        return $this->scheduler->every($seconds, $callback);
    }

    public function router(): Router
    {
        $this->loadRoutes();

        return $this->router;
    }

    public function scheduler(): Scheduler
    {
        $this->loadRoutes();

        return $this->scheduler;
    }

    public function notifications(): NotificationRouter
    {
        $this->loadRoutes();

        return $this->notifications;
    }

    /**
     * @internal Called by harken:start once the connection exists.
     */
    public function usePublisher(Publisher $publisher): void
    {
        $this->publisher = $publisher;
    }

    protected function loadRoutes(): void
    {
        if ($this->routesLoaded) {
            return;
        }

        $this->routesLoaded = true;

        if ($this->routesPath !== null && is_file($this->routesPath)) {
            (static function (string $path): void {
                require $path;
            })($this->routesPath);
        }
    }
}
