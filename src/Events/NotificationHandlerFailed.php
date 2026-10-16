<?php

declare(strict_types=1);

namespace Whatef\Harken\Events;

use Throwable;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

/**
 * A PostgreSQL NOTIFY handler threw on the event loop, or its job could not
 * be queued. The runtime keeps running.
 *
 * $failedJobId is set when harken.handlers.failed_jobs stored the
 * notification in the queue's failed job store.
 */
final readonly class NotificationHandlerFailed
{
    public function __construct(
        public NotificationRoute $route,
        public Notification $notification,
        public Throwable $exception,
        public string|int|null $failedJobId = null,
    ) {}
}
