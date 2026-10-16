<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

/**
 * Runs (or hands off) the handler of a NOTIFY channel. The Laravel bridge
 * resolves handlers from the container and queues ->queued() ones.
 */
interface NotificationInvoker
{
    public function invokeNotification(NotificationRoute $route, Notification $notification): void;
}
