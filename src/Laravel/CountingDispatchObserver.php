<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Throwable;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\RuntimeStats;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

/**
 * Counts handler outcomes for the status report, then hands them on.
 */
final readonly class CountingDispatchObserver implements DispatchObserver, NotificationObserver
{
    public function __construct(
        private DispatchObserver $messages,
        private NotificationObserver $notifications,
        private RuntimeStats $stats,
    ) {}

    public function unmatched(Message $message): void
    {
        $this->stats->unmatched++;
        $this->messages->unmatched($message);
    }

    public function handled(Route $route, Message $message, float $milliseconds): void
    {
        $this->messages->handled($route, $message, $milliseconds);
    }

    public function slow(Route $route, Message $message, float $milliseconds): void
    {
        $this->stats->slowHandlers++;
        $this->messages->slow($route, $message, $milliseconds);
    }

    public function failed(Route $route, Message $message, Throwable $exception, float $milliseconds): void
    {
        $this->stats->handlerFailures++;
        $this->messages->failed($route, $message, $exception, $milliseconds);
    }

    public function notificationHandled(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $this->notifications->notificationHandled($route, $notification, $milliseconds);
    }

    public function notificationSlow(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $this->stats->slowHandlers++;
        $this->notifications->notificationSlow($route, $notification, $milliseconds);
    }

    public function notificationFailed(NotificationRoute $route, Notification $notification, Throwable $exception, float $milliseconds): void
    {
        $this->stats->handlerFailures++;
        $this->notifications->notificationFailed($route, $notification, $exception, $milliseconds);
    }
}
