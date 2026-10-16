<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use Closure;
use InvalidArgumentException;
use Whatef\Harken\Runtime\Routing\Distribution;

/**
 * A PostgreSQL NOTIFY channel bound to a handler, configured fluently:
 *
 *     Harken::listen('outbox_ready', SweepPending::class);              // leader only
 *     Harken::listen('config_changed', RefreshCache::class)->broadcast(); // every replica
 *
 * PostgreSQL delivers a NOTIFY to every listening connection, so by default
 * only the elected leader replica listens and one process reacts.
 */
final class NotificationRoute
{
    private Distribution $distribution = Distribution::Leader;

    private bool $queued = false;

    /** @var class-string|callable */
    private readonly mixed $handler;

    /**
     * @param  class-string|callable  $handler  An invokable class name resolved per call, or a callable.
     */
    public function __construct(public readonly string $channel, string|callable $handler)
    {
        if ($channel === '' || strlen($channel) > 63 || str_contains($channel, "\0")) {
            throw new InvalidArgumentException("Invalid PostgreSQL channel [{$channel}]: use 1 to 63 bytes without NUL.");
        }

        $this->handler = $handler;
    }

    /**
     * Only the elected leader replica listens. This is the default.
     */
    public function leader(): static
    {
        $this->distribution = Distribution::Leader;

        return $this;
    }

    /**
     * Every replica listens and handles every notification.
     */
    public function broadcast(): static
    {
        $this->distribution = Distribution::Broadcast;

        return $this;
    }

    /**
     * Run the handler on a Laravel queue worker.
     */
    public function queued(): static
    {
        if (! is_string($this->handler) || is_callable($this->handler)) {
            throw new InvalidArgumentException("The listener of channel [{$this->channel}] can only be queued when its handler is a class name.");
        }

        $this->queued = true;

        return $this;
    }

    /**
     * @return class-string|callable
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    public function getDistribution(): Distribution
    {
        return $this->distribution;
    }

    public function isQueued(): bool
    {
        return $this->queued;
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
