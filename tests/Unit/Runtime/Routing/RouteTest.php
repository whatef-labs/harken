<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Routing\Route;

it('defaults to QoS 0, inline, on the leader', function () {
    $route = new Route('a/{id}', 'App\\Handler');

    expect($route->getQos())->toBe(0)
        ->and($route->isQueued())->toBeFalse()
        ->and($route->getDistribution())->toBe(Distribution::Leader)
        ->and($route->getSharedGroup())->toBeNull()
        ->and($route->subscriptionFilter())->toBe('a/+')
        ->and($route->handlerName())->toBe('App\\Handler');
});

it('configures fluently', function () {
    $route = (new Route('a/{id}', 'App\\Handler'))->qos(1)->shared('g1')->queued();

    expect($route->getQos())->toBe(1)
        ->and($route->isQueued())->toBeTrue()
        ->and($route->subscriptionFilter())->toBe('$share/g1/a/+');
});

it('picks one distribution, the last one called', function () {
    $route = new Route('a/{id}', 'App\\Handler');

    expect($route->shared('g')->getDistribution())->toBe(Distribution::Shared)
        ->and($route->broadcast()->getDistribution())->toBe(Distribution::Broadcast)
        ->and($route->getSharedGroup())->toBeNull()
        ->and($route->subscriptionFilter())->toBe('a/+')
        ->and($route->shared('g')->leader()->getDistribution())->toBe(Distribution::Leader)
        ->and($route->subscriptionFilter())->toBe('a/+');
});

it('rejects unsupported QoS levels', function (int $qos) {
    (new Route('a', fn () => null))->qos($qos);
})->with([2, 3, -1])->throws(InvalidArgumentException::class);

it('only queues class handlers', function () {
    (new Route('a', fn () => null))->queued();
})->throws(InvalidArgumentException::class, 'can only be queued');

it('rejects invalid shared group names', function (string $group) {
    (new Route('a', fn () => null))->shared($group);
})->with(['', 'a/b', 'a+', '#'])->throws(InvalidArgumentException::class);

it('names closure handlers', function () {
    expect((new Route('a', fn () => null))->handlerName())->toBe('Closure');
});
