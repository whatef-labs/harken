<?php

declare(strict_types=1);

namespace Whatef\Harken\Events;

use Throwable;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\Route;

/**
 * A route's handler threw on the event loop, or its job could not be
 * queued. The runtime keeps running and the message is still acknowledged.
 *
 * $failedJobId is set when harken.handlers.failed_jobs stored the message
 * in the queue's failed job store, from where `php artisan queue:retry`
 * runs the handler again on a queue worker.
 */
final readonly class MessageHandlerFailed
{
    public function __construct(
        public Route $route,
        public Message $message,
        public Throwable $exception,
        public string|int|null $failedJobId = null,
    ) {}
}
