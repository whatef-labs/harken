<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use Throwable;

interface NotificationObserver
{
    public function notificationHandled(NotificationRoute $route, Notification $notification, float $milliseconds): void;

    public function notificationSlow(NotificationRoute $route, Notification $notification, float $milliseconds): void;

    public function notificationFailed(NotificationRoute $route, Notification $notification, Throwable $exception, float $milliseconds): void;
}
