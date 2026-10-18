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
 * $sessionPresent, set on the change to connected only, is the CONNACK's
 * session present flag: whether the broker resumed a session it kept for
 * the client id. The leader connection asks the broker to keep its session,
 * so false there after a takeover or a reconnect means the broker discarded
 * it (expired, or evicted under a memory limit) together with the QoS 1
 * messages it held.
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
        public ?bool $sessionPresent = null,
    ) {}
}
