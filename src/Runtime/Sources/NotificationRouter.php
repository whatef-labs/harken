<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use Whatef\Harken\Runtime\Routing\Distribution;

/**
 * NOTIFY channels and their handlers. Every handler registered for a
 * channel runs, in registration order.
 */
final class NotificationRouter
{
    /** @var list<NotificationRoute> */
    private array $routes = [];

    public function add(NotificationRoute $route): NotificationRoute
    {
        $this->routes[] = $route;

        return $route;
    }

    /**
     * @return list<NotificationRoute>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * The routes with one of the given distributions.
     */
    public function only(Distribution ...$distributions): self
    {
        $subset = new self;

        foreach ($this->routes as $route) {
            if (in_array($route->getDistribution(), $distributions, true)) {
                $subset->routes[] = $route;
            }
        }

        return $subset;
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return array_values(array_unique(array_map(static fn (NotificationRoute $r): string => $r->channel, $this->routes)));
    }

    /**
     * @return list<NotificationRoute>
     */
    public function match(string $channel): array
    {
        return array_values(array_filter($this->routes, static fn (NotificationRoute $r): bool => $r->channel === $channel));
    }

    public function hasQueuedRoutes(): bool
    {
        foreach ($this->routes as $route) {
            if ($route->isQueued()) {
                return true;
            }
        }

        return false;
    }
}
