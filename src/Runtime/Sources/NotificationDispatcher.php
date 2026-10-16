<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use Throwable;

/**
 * Runs every handler registered for a notification's channel inside the
 * event loop. A handler exception is reported and swallowed; handlers
 * slower than the threshold are reported.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationRouter $router,
        private readonly NotificationInvoker $invoker,
        private readonly NotificationObserver $observer,
        private readonly float $slowThresholdMs = 100.0,
    ) {}

    public function dispatch(Notification $notification): void
    {
        foreach ($this->router->match($notification->channel) as $route) {
            $started = hrtime(true);

            try {
                $this->invoker->invokeNotification($route, $notification);
            } catch (Throwable $e) {
                $this->observer->notificationFailed($route, $notification, $e, (hrtime(true) - $started) / 1e6);

                continue;
            }

            $elapsed = (hrtime(true) - $started) / 1e6;
            $this->observer->notificationHandled($route, $notification, $elapsed);

            if ($elapsed > $this->slowThresholdMs) {
                $this->observer->notificationSlow($route, $notification, $elapsed);
            }
        }
    }
}
