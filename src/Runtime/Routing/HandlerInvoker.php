<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

use Whatef\Harken\Runtime\Message;

/**
 * Runs (or hands off) the handler of a matched route. The runtime does not
 * know how handlers are resolved; the Laravel bridge uses the container and
 * the queue.
 */
interface HandlerInvoker
{
    public function invoke(Route $route, Message $message): void;
}
