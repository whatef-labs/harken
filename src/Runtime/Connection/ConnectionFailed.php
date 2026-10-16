<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use RuntimeException;
use Throwable;

/**
 * A connection attempt failed before the broker answered: the socket could
 * not be opened, TLS failed, or no CONNACK or SUBACK arrived in time.
 */
final class ConnectionFailed extends RuntimeException
{
    public static function unreachable(string $uri, Throwable $previous): self
    {
        return new self("Cannot connect to {$uri}: {$previous->getMessage()}", 0, $previous);
    }

    public static function noConnAck(float $seconds): self
    {
        return new self(sprintf('The broker did not answer CONNECT within %s s.', $seconds));
    }

    public static function noSubAck(float $seconds): self
    {
        return new self(sprintf('The broker did not answer SUBSCRIBE within %s s.', $seconds));
    }

    public static function gaveUp(int $attempts, Throwable $previous): self
    {
        return new self("Gave up connecting after {$attempts} failed attempts: {$previous->getMessage()}", 0, $previous);
    }
}
