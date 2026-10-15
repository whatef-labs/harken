<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use InvalidArgumentException;

/**
 * Builds a client identifier that differs per replica, so several processes
 * started from the same configuration do not take over each other's session.
 *
 * Format: `{prefix}-{hostname}-{pid}-{random}`. The random part covers
 * containers that share a hostname and PID (for example `network_mode: host`
 * with PID 1 in each). A process that needs a stable identity for a
 * persistent session (clean_session = false) must set an explicit client id.
 */
final class ClientId
{
    public static function generate(
        string $prefix,
        ?string $hostname = null,
        ?int $pid = null,
        ?string $random = null,
    ): string {
        $prefix = self::sanitize($prefix);

        if ($prefix === '') {
            throw new InvalidArgumentException('The client id prefix must contain at least one of [A-Za-z0-9_-].');
        }

        $hostname = self::sanitize($hostname ?? (gethostname() ?: 'host'));
        $pid ??= getmypid() ?: 0;
        $random ??= bin2hex(random_bytes(3));

        return sprintf('%s-%s-%d-%s', $prefix, $hostname === '' ? 'host' : $hostname, $pid, $random);
    }

    private static function sanitize(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $value);
    }
}
