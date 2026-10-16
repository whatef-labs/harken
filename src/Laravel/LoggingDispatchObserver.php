<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Psr\Log\LoggerInterface;
use Throwable;
use Whatef\Harken\Events\MessageHandlerFailed;
use Whatef\Harken\Events\NotificationHandlerFailed;
use Whatef\Harken\Laravel\Queue\FailedMessageStore;
use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

/**
 * Logs and counts handler outcomes. A failed handler is also reported to the
 * exception handler, announced with a MessageHandlerFailed (or
 * NotificationHandlerFailed) event and, when a failed message store is
 * given, stored as a retriable failed job.
 */
final readonly class LoggingDispatchObserver implements DispatchObserver, NotificationObserver
{
    public function __construct(
        private LoggerInterface $logger,
        private LogContext $context,
        private Metrics $metrics,
        private ExceptionHandler $exceptions,
        private ?Events $events = null,
        private ?FailedMessageStore $failed = null,
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
        $this->metrics->observe('harken_handler_seconds', $milliseconds / 1000, $labels);
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
        $id = $this->guard(fn () => $this->failed?->message($route, $message, $exception));
        $this->logger->error(
            'Harken handler failed; the runtime keeps running.',
            $this->context->message($message) + [
                'handler' => $route->handlerName(),
                'exception' => $exception::class,
                'failed_job_id' => $id,
            ],
        );
        $this->guard(fn () => $this->events?->dispatch(new MessageHandlerFailed($route, $message, $exception, $id)));
    }

    public function notificationHandled(NotificationRoute $route, Notification $notification, float $milliseconds): void
    {
        $labels = ['channel' => $route->channel];

        $this->metrics->increment('harken_notifications_handled_total', 1, $labels);
        $this->metrics->observe('harken_handler_seconds', $milliseconds / 1000, $labels);
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
        $id = $this->guard(fn () => $this->failed?->notification($route, $notification, $exception));
        $this->logger->error('Harken notification handler failed; the runtime keeps running.', [
            'channel' => $route->channel,
            'resync' => $notification->resync,
            'handler' => $route->handlerName(),
            'exception' => $exception::class,
            'failed_job_id' => $id,
        ]);
        $this->guard(fn () => $this->events?->dispatch(new NotificationHandlerFailed($route, $notification, $exception, $id)));
    }

    /**
     * Runs a follow-up of a failure (storing it, an event listener) without
     * letting its own exception escape into the runtime.
     *
     * @param  callable(): mixed  $callback
     */
    private function guard(callable $callback): string|int|null
    {
        try {
            $result = $callback();

            return is_string($result) || is_int($result) ? $result : null;
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            return null;
        }
    }
}
