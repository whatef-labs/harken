<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\SyncQueue;
use Throwable;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

/**
 * Stores a message whose handler failed on the event loop in the queue's
 * failed job store, as the HandleMessage (or HandleNotification) job that
 * runs the same handler. `php artisan queue:retry` then pushes it onto the
 * default queue connection, where a worker runs the handler again.
 *
 * Only class handlers can be stored; closures cannot be serialized.
 */
final readonly class FailedMessageStore
{
    public function __construct(
        private FailedJobProviderInterface $failer,
        private ContainerContract $container,
        private Config $config,
    ) {}

    public function message(Route $route, Message $message, Throwable $exception): string|int|null
    {
        $handler = $route->getHandler();

        return is_string($handler) && class_exists($handler) ? $this->store(new HandleMessage($handler, $message), $exception) : null;
    }

    public function notification(NotificationRoute $route, Notification $notification, Throwable $exception): string|int|null
    {
        $handler = $route->getHandler();

        return is_string($handler) && class_exists($handler) ? $this->store(new HandleNotification($handler, $notification), $exception) : null;
    }

    private function store(object $job, Throwable $exception): string|int|null
    {
        $connection = $this->config->get('queue.default', 'sync');
        $connection = is_string($connection) ? $connection : 'sync';
        $queue = $this->config->get("queue.connections.{$connection}.queue", 'default');
        $queue = is_string($queue) ? $queue : 'default';

        return $this->failer->log($connection, $queue, $this->payload($job, $queue), $exception);
    }

    /**
     * The payload a queue would store for the job, so the failed entry can
     * be retried like any other failed job.
     */
    private function payload(object $job, string $queue): string
    {
        $builder = new class extends SyncQueue
        {
            public function payloadFor(object $job, string $queue): string
            {
                return $this->createPayload($job, $queue);
            }
        };
        $builder->setContainer($this->container instanceof Container ? $this->container : Container::getInstance());

        return $builder->payloadFor($job, $queue);
    }
}
