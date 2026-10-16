<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\Queue\HandleNotification;
use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationInvoker;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

final class SweepPendingHandler
{
    /** @var list<Notification> */
    public static array $seen = [];

    public function __invoke(Notification $notification): void
    {
        self::$seen[] = $notification;
    }
}

beforeEach(function () {
    SweepPendingHandler::$seen = [];
});

it('registers NOTIFY handlers on the leader unless broadcast', function () {
    Harken::listen('outbox_ready', SweepPendingHandler::class);
    Harken::listen('config_changed', fn () => null)->broadcast();

    $notifications = app(HarkenManager::class)->notifications();

    expect($notifications->only(Distribution::Leader)->channels())->toBe(['outbox_ready'])
        ->and($notifications->only(Distribution::Broadcast)->channels())->toBe(['config_changed']);
});

it('resolves NOTIFY handlers from the container', function () {
    app(NotificationInvoker::class)->invokeNotification(
        new NotificationRoute('outbox_ready', SweepPendingHandler::class),
        new Notification('outbox_ready', '42'),
    );

    expect(SweepPendingHandler::$seen)->toEqual([new Notification('outbox_ready', '42')]);
});

it('queues ->queued() NOTIFY handlers', function () {
    Bus::fake();

    app(NotificationInvoker::class)->invokeNotification(
        (new NotificationRoute('outbox_ready', SweepPendingHandler::class))->queued(),
        new Notification('outbox_ready', '', resync: true),
    );

    Bus::assertDispatched(HandleNotification::class, fn (HandleNotification $job) => $job->handler === SweepPendingHandler::class
        && $job->notification->resync);
    expect(SweepPendingHandler::$seen)->toBe([]);
});

it('runs a queued NOTIFY handler from its serialized job', function () {
    $job = unserialize(serialize(new HandleNotification(SweepPendingHandler::class, new Notification('outbox_ready', '7'))));

    app()->call([$job, 'handle']);

    expect(SweepPendingHandler::$seen)->toEqual([new Notification('outbox_ready', '7')]);
});
