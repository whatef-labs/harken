<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

/**
 * disconnected -> connecting -> connected -> reconnecting -> connecting ...
 * Any active state can move to stopping, and stopping ends in disconnected.
 */
enum ConnectionState: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Reconnecting = 'reconnecting';
    case Stopping = 'stopping';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Disconnected => [self::Connecting],
            self::Connecting => [self::Connected, self::Reconnecting, self::Stopping, self::Disconnected],
            self::Connected => [self::Reconnecting, self::Stopping],
            self::Reconnecting => [self::Connecting, self::Stopping],
            self::Stopping => [self::Disconnected],
        }, true);
    }
}
