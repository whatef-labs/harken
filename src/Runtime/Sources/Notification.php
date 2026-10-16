<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

/**
 * A PostgreSQL NOTIFY as handed to handlers.
 *
 * NOTIFY is a hint, not a queue: notifications sent while nobody listens
 * are gone, and identical ones in one transaction are folded into one. Each
 * time the runtime starts (or restarts) listening on a channel, it calls the
 * handlers once with $resync set and an empty payload, so they can look for
 * work they may have missed.
 */
final readonly class Notification
{
    public function __construct(
        public string $channel,
        public string $payload = '',
        public bool $resync = false,
    ) {}
}
