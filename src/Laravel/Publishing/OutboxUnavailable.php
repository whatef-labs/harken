<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Publishing;

use RuntimeException;
use Throwable;

/**
 * Harken::publish() outside the runtime could not store the message in the
 * outbox stream. Nothing was queued.
 */
final class OutboxUnavailable extends RuntimeException
{
    public static function because(string $topic, ?Throwable $previous = null): self
    {
        $detail = $previous === null ? 'Redis did not return an entry id' : $previous->getMessage();

        return new self("Cannot queue the publish to [{$topic}] in the Harken outbox: {$detail}", 0, $previous);
    }
}
