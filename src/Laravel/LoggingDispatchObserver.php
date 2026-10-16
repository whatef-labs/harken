<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Psr\Log\LoggerInterface;
use Throwable;
use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

final readonly class LoggingDispatchObserver implements DispatchObserver, NotificationObserver
{
    public function __construct(
        private LoggerInterface $logger,
        private LogContext $context,
        private Metrics $metrics,
        private ExceptionHandler $exceptions,
    ) {}

    public function unmatched(Message $message): void
    {
        $this->metrics->increment('harken_messages_unmatched_total');
        $this->logger->warning('Harken received a message that matches no route.', $this->context->message($message));
    }

    public function handled(Route $route, Message $message, float $milliseconds): void
    {
        $labels = ['route' => $route->pattern->pattern];

        $this->metrics->increment('harken_messages_handled_total', 1, $labels);
        $this->metrics->observe('harken_handler_milliseconds', $milliseconds, $labels);
    }

    public function slow(Route $route, Message $message, float $milliseconds): void
    {
        $this->metrics->increment('harken_handlers_slow_total', 1, ['route' => $route->pattern->pattern]);
        $this->logger->warning(
            'Harken handler blocked the event loop; consider ->queued().',
            $this->context->message($message) + [
                'handler' => $route->handlerName(),
                'milliseconds' => round($milliseconds, 2),
            ],
        );
    }

    public function failed(Route $route, Message $message, Throwable $exception, float $milliseconds): void
    {
        $this->metrics->increment('harken_handlers_failed_total', 1, ['route' => $route->pattern->pattern]);
        $this->exceptions->report($exception);
        $this->logger->error(
            'Harken handler failed; the runtime keeps running.',
            $this->context->message($message) + [
                'handler' => $route->handlerName(),
                'exception' => $exception::class,
            ],
        );
    }

    public function notificationHandled(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $labels = ['channel' => $route->channel];

        $this->metrics->increment('harken_notifications_handled_total', 1, $labels);
        $this->metrics->observe('harken_handler_milliseconds', $milliseconds, $labels);
    }

    public function notificationSlow(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $this->metrics->increment('harken_handlers_slow_total', 1, ['channel' => $route->channel]);
        $this->logger->warning('Harken notification handler blocked the event loop; consider ->queued().', [
            'channel' => $route->channel,
            'handler' => $route->handlerName(),
            'milliseconds' => round($milliseconds, 2),
        ]);
    }

    public function notificationFailed(NotificationRoute $route, Notification $notification, Throwable $exception, float $milliseconds): void
    {
        $this->metrics->increment('harken_handlers_failed_total', 1, ['channel' => $route->channel]);
        $this->exceptions->report($exception);
        $this->logger->error('Harken notification handler failed; the runtime keeps running.', [
            'channel' => $route->channel,
            'resync' => $notification->resync,
            'handler' => $route->handlerName(),
            'exception' => $exception::class,
        ]);
    }
}
