<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationDispatcher;
use Whatef\Harken\Runtime\Sources\NotificationInvoker;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Sources\NotificationRoute;
use Whatef\Harken\Runtime\Sources\NotificationRouter;

final class CallingNotificationInvoker implements NotificationInvoker
{
    public function invokeNotification(NotificationRoute $route, Notification $notification): void
    {
        ($route->getHandler())($notification);
    }
}

final class RecordingNotificationObserver implements NotificationObserver
{
    /** @var list<string> */
    public array $events = [];

    public function notificationHandled(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $this->events[] = "handled {$route->channel}";
    }

    public function notificationSlow(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $this->events[] = "slow {$route->channel}";
    }

    public function notificationFailed(NotificationRoute $route, Notification $notification, Throwable $exception, float $milliseconds): void
    {
        $this->events[] = "failed {$route->channel}: {$exception->getMessage()}";
    }
}

it('listens on the leader by default and can broadcast or queue', function () {
    $route = new NotificationRoute('outbox_ready', 'App\\SweepPending');

    expect($route->getDistribution())->toBe(Distribution::Leader)
        ->and($route->isQueued())->toBeFalse()
        ->and($route->broadcast()->getDistribution())->toBe(Distribution::Broadcast)
        ->and($route->leader()->queued()->isQueued())->toBeTrue()
        ->and($route->handlerName())->toBe('App\\SweepPending');
});

it('rejects channel names PostgreSQL cannot listen on', function (string $channel) {
    new NotificationRoute($channel, fn () => null);
})->with(['', str_repeat('c', 64), "a\0b"])->throws(InvalidArgumentException::class);

it('only queues class handlers', function () {
    (new NotificationRoute('c', fn () => null))->queued();
})->throws(InvalidArgumentException::class, 'can only be queued');

it('splits channels by distribution and runs every handler of a channel', function () {
    $router = new NotificationRouter;
    $calls = [];
    $router->add(new NotificationRoute('outbox_ready', function (Notification $n) use (&$calls) {
        $calls[] = "sweep {$n->payload}";
    }));
    $router->add(new NotificationRoute('outbox_ready', function (Notification $n) use (&$calls) {
        $calls[] = "count {$n->payload}";
    }));
    $router->add((new NotificationRoute('config_changed', fn () => null))->broadcast());

    $leader = $router->only(Distribution::Leader);
    (new NotificationDispatcher($leader, new CallingNotificationInvoker, new RecordingNotificationObserver))
        ->dispatch(new Notification('outbox_ready', '42'));

    expect($leader->channels())->toBe(['outbox_ready'])
        ->and($router->only(Distribution::Broadcast)->channels())->toBe(['config_changed'])
        ->and($calls)->toBe(['sweep 42', 'count 42']);
});

it('reports a failing handler and keeps running the others', function () {
    $router = new NotificationRouter;
    $ran = false;
    $router->add(new NotificationRoute('c', fn () => throw new RuntimeException('boom')));
    $router->add(new NotificationRoute('c', function () use (&$ran) {
        $ran = true;
    }));
    $observer = new RecordingNotificationObserver;

    (new NotificationDispatcher($router, new CallingNotificationInvoker, $observer))->dispatch(new Notification('c'));

    expect($ran)->toBeTrue()
        ->and($observer->events)->toBe(['failed c: boom', 'handled c']);
});

it('reports slow notification handlers', function () {
    $router = new NotificationRouter;
    $router->add(new NotificationRoute('c', fn () => usleep(3000)));
    $observer = new RecordingNotificationObserver;

    (new NotificationDispatcher($router, new CallingNotificationInvoker, $observer, slowThresholdMs: 1.0))->dispatch(new Notification('c'));

    expect($observer->events)->toBe(['handled c', 'slow c']);
});
