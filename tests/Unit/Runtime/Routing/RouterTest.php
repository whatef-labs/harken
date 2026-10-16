<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Routing\Router;

it('returns the first registered route that matches', function () {
    $router = new Router;
    $specific = $router->add(new Route('sensors/{id}/temperature', fn () => null));
    $router->add(new Route('sensors/#', fn () => null));

    $match = $router->match('sensors/7/temperature');

    expect($match?->route)->toBe($specific)
        ->and($match?->params)->toBe(['id' => '7']);
});

it('returns null when nothing matches', function () {
    $router = new Router;
    $router->add(new Route('a/b', fn () => null));

    expect($router->match('a/c'))->toBeNull();
});

it('lists unique subscription filters with the highest requested QoS', function () {
    $router = new Router;
    $router->add(new Route('a/{id}', fn () => null));
    $router->add((new Route('a/+', fn () => null))->qos(1));
    $router->add((new Route('b/{id}', 'App\\Handler'))->qos(1)->shared('workers'));

    expect($router->subscriptions())->toBe([
        'a/+' => 1,
        '$share/workers/b/+' => 1,
    ]);
});

it('splits routes by distribution for each connection', function () {
    $router = new Router;
    $router->add(new Route('a/#', fn () => 'leader-wide'));
    $router->add((new Route('a/b', fn () => 'broadcast'))->broadcast());
    $router->add((new Route('c', fn () => 'shared'))->shared('g'));
    $router->add((new Route('d', fn () => 'leader'))->leader());

    $leader = $router->only(Distribution::Leader);
    $replica = $router->only(Distribution::Shared, Distribution::Broadcast);

    expect($leader->subscriptions())->toBe(['a/#' => 0, 'd' => 0])
        ->and($replica->subscriptions())->toBe(['a/b' => 0, '$share/g/c' => 0])
        ->and(($leader->match('a/b')?->route->getHandler())())->toBe('leader-wide')
        ->and(($replica->match('a/b')?->route->getHandler())())->toBe('broadcast')
        ->and($router->routes())->toHaveCount(4);
});

it('knows whether any route is queued', function () {
    $router = new Router;
    $router->add(new Route('a', fn () => null));

    expect($router->hasQueuedRoutes())->toBeFalse();

    $router->add((new Route('b', 'App\\Handler'))->queued());

    expect($router->hasQueuedRoutes())->toBeTrue();
});
