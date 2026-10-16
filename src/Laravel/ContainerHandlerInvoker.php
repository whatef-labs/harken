<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Whatef\Harken\Laravel\Queue\HandleMessage;
use Whatef\Harken\Laravel\Queue\HandleNotification;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationInvoker;
use Whatef\Harken\Runtime\Sources\NotificationRoute;

/**
 * Resolves class handlers from the container on every message, the way
 * controllers are resolved per request. Bind a handler as a singleton to
 * reuse one instance for the life of the process.
 */
final readonly class ContainerHandlerInvoker implements HandlerInvoker, NotificationInvoker
{
    public function __construct(
        private Container $container,
        private Bus $bus,
    ) {}

    public function invoke(Route $route, Message $message): void
    {
        $handler = $route->getHandler();

        if ($route->isQueued() && is_string($handler)) {
            $this->bus->dispatch(new HandleMessage($handler, $message));

            return;
        }

        if (is_string($handler)) {
            $handler = $this->container->make($handler);
        }

        if (! is_callable($handler)) {
            throw new InvalidArgumentException("The handler of route [{$route->pattern->pattern}] is not invokable.");
        }

        $handler($message);
    }

    public function invokeNotification(NotificationRoute $route, Notification $notification): void
    {
        $handler = $route->getHandler();

        if ($route->isQueued() && is_string($handler)) {
            $this->bus->dispatch(new HandleNotification($handler, $notification));

            return;
        }

        if (is_string($handler)) {
            $handler = $this->container->make($handler);
        }

        if (! is_callable($handler)) {
            throw new InvalidArgumentException("The handler of channel [{$route->channel}] is not invokable.");
        }

        $handler($notification);
    }
}
