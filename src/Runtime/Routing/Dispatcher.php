<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

use Throwable;
use Whatef\Harken\Runtime\Message;

/**
 * Routes one inbound message to its handler inside the event loop.
 *
 * A handler exception is reported and swallowed so that it cannot stop the
 * loop or drop the connection. Handlers slower than the threshold are
 * reported so blocking calls show up in logs and metrics.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Router $router,
        private readonly HandlerInvoker $invoker,
        private readonly DispatchObserver $observer,
        private readonly float $slowThresholdMs = 100.0,
    ) {}

    /**
     * @return bool Whether a route matched.
     */
    public function dispatch(Message $message): bool
    {
        $match = $this->router->match($message->topic);

        if ($match === null) {
            $this->observer->unmatched($message);

            return false;
        }

        $message = $match->params === [] ? $message : $message->withParams($match->params);
        $started = hrtime(true);

        try {
            $this->invoker->invoke($match->route, $message);
        } catch (Throwable $e) {
            $this->observer->failed($match->route, $message, $e, (hrtime(true) - $started) / 1e6);

            return true;
        }

        $elapsed = (hrtime(true) - $started) / 1e6;
        $this->observer->handled($match->route, $message, $elapsed);

        if ($elapsed > $this->slowThresholdMs) {
            $this->observer->slow($match->route, $message, $elapsed);
        }

        return true;
    }
}
