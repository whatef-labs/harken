<?php

declare(strict_types=1);

namespace Whatef\Harken\Events;

use Throwable;
use Whatef\Harken\Runtime\Connection\ConnectionState;

/**
 * A broker connection of the runtime moved from one state to another:
 * disconnected -> connecting -> connected -> reconnecting -> ... -> stopping.
 *
 * $connection is "main" (publishing, shared and broadcast routes) or
 * "leader" (leader routes, on the leader replica only). $error is what made
 * the connection leave the connected state, when there was a problem.
 *
 * Listeners run on the event loop: keep them short, or make them queued
 * (ShouldQueue).
 */
final readonly class ConnectionStateChanged
{
    public function __construct(
        public string $connection,
        public string $clientId,
        public ConnectionState $from,
        public ConnectionState $to,
        public ?Throwable $error = null,
    ) {}
}
