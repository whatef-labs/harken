<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

final readonly class RouteMatch
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(
        public Route $route,
        public array $params,
    ) {}
}
