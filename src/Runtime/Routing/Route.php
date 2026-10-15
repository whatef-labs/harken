<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

use Closure;
use InvalidArgumentException;

/**
 * A topic pattern bound to a handler, configured fluently:
 *
 *     Harken::topic('sensors/{id}/status', StatusHandler::class)->qos(1)->shared('ingest')->queued();
 */
final class Route
{
    public readonly TopicPattern $pattern;

    private int $qos = 0;

    private bool $queued = false;

    private ?string $sharedGroup = null;

    /** @var class-string|callable */
    private readonly mixed $handler;

    /**
     * @param  class-string|callable  $handler  An invokable class name resolved per dispatch, or a callable.
     */
    public function __construct(string $pattern, string|callable $handler)
    {
        $this->pattern = new TopicPattern($pattern);
        $this->handler = $handler;
    }

    public function qos(int $qos): static
    {
        if ($qos === 2) {
            throw new InvalidArgumentException('QoS 2 subscriptions are not supported yet.');
        }

        if ($qos !== 0 && $qos !== 1) {
            throw new InvalidArgumentException("Invalid QoS level [{$qos}].");
        }

        $this->qos = $qos;

        return $this;
    }

    /**
     * Run the handler outside the event loop. The runtime only wraps the
     * message in a job; the handler runs later on a queue worker.
     */
    public function queued(): static
    {
        if (! is_string($this->handler) || is_callable($this->handler)) {
            throw new InvalidArgumentException(
                "Route [{$this->pattern->pattern}] can only be queued when its handler is a class name."
            );
        }

        $this->queued = true;

        return $this;
    }

    /**
     * Subscribe as a member of an MQTT shared subscription group, so each
     * message is delivered to only one replica of the group.
     */
    public function shared(string $group): static
    {
        if ($group === '' || strpbrk($group, '/+#') !== false) {
            throw new InvalidArgumentException("Invalid shared subscription group [{$group}].");
        }

        $this->sharedGroup = $group;

        return $this;
    }

    /**
     * @return class-string|callable
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    public function getQos(): int
    {
        return $this->qos;
    }

    public function isQueued(): bool
    {
        return $this->queued;
    }

    public function getSharedGroup(): ?string
    {
        return $this->sharedGroup;
    }

    /**
     * The filter sent in SUBSCRIBE, including the `$share/{group}/` prefix.
     */
    public function subscriptionFilter(): string
    {
        $filter = $this->pattern->filter();

        return $this->sharedGroup === null ? $filter : "\$share/{$this->sharedGroup}/{$filter}";
    }

    public function handlerName(): string
    {
        return match (true) {
            is_string($this->handler) => $this->handler,
            $this->handler instanceof Closure => 'Closure',
            is_object($this->handler) => $this->handler::class,
            default => 'callable',
        };
    }
}
