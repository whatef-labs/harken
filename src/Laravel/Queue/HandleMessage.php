<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Whatef\Harken\Runtime\Message;

/**
 * Runs a `->queued()` route handler on a queue worker. The message keeps its
 * exact payload bytes through serialization.
 */
final class HandleMessage implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  string  $handler  Class name of an invokable handler.
     */
    public function __construct(
        public readonly string $handler,
        public readonly Message $message,
    ) {}

    public function handle(Container $container): void
    {
        $handler = $container->make($this->handler);

        if (! is_callable($handler)) {
            throw new InvalidArgumentException("Harken handler [{$this->handler}] is not invokable.");
        }

        $handler($this->message);
    }

    public function displayName(): string
    {
        return $this->handler;
    }
}
