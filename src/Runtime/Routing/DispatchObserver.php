<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

use Throwable;
use Whatef\Harken\Runtime\Message;

interface DispatchObserver
{
    public function unmatched(Message $message): void;

    public function handled(Route $route, Message $message, float $milliseconds): void;

    public function slow(Route $route, Message $message, float $milliseconds): void;

    public function failed(Route $route, Message $message, Throwable $exception, float $milliseconds): void;
}
