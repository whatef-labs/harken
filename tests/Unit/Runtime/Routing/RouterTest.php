<?php

declare(strict_types=1);

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

it('knows whether any route is queued', function () {
    $router = new Router;
    $router->add(new Route('a', fn () => null));

    expect($router->hasQueuedRoutes())->toBeFalse();

    $router->add((new Route('b', 'App\\Handler'))->queued());

    expect($router->hasQueuedRoutes())->toBeTrue();
});
