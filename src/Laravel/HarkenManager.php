<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use React\Promise\PromiseInterface;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Routing\Router;
use Whatef\Harken\Runtime\Timers\Scheduler;
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
    ) {}

    /**
     * @param  class-string|callable  $handler
     */
    public function topic(string $pattern, string|callable $handler): Route
    {
        return $this->router->add(new Route($pattern, $handler));
    }

    /**
     * @return PromiseInterface<mixed>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): PromiseInterface
    {
        Topic::assertPublishable($topic, $qos);

        return $this->publisher->publish($topic, $payload, $qos, $retain);
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
     * @param  callable(): mixed  $callback
     */
    public function every(int|float $seconds, callable $callback): void
    {
        $this->scheduler->every($seconds, $callback);
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
