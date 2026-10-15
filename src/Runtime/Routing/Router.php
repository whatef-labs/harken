<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

/**
 * Route table. The first registered route that matches a topic wins.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(Route $route): Route
    {
        $this->routes[] = $route;

        return $route;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function match(string $topic): ?RouteMatch
    {
        foreach ($this->routes as $route) {
            $params = $route->pattern->match($topic);

            if ($params !== null) {
                return new RouteMatch($route, $params);
            }
        }

        return null;
    }

    /**
     * Filters to subscribe to, de-duplicated, each with the highest QoS any
     * route requested for it.
     *
     * @return array<string, int>
     */
    public function subscriptions(): array
    {
        $filters = [];

        foreach ($this->routes as $route) {
            $filter = $route->subscriptionFilter();
            $filters[$filter] = max($filters[$filter] ?? 0, $route->getQos());
        }

        return $filters;
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
