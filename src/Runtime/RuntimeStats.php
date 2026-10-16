<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

/**
 * Counters the observers of one runtime keep for status reports. The
 * connection, election and outbox report their own live state.
 */
final class RuntimeStats
{
    public int $handlerFailures = 0;

    public int $slowHandlers = 0;

    public int $unmatched = 0;

    public int $outboxPublished = 0;

    public int $outboxDropped = 0;

    /** @var array<string, int> Stream => entries waiting, from the last check. */
    public array $outboxLengths = [];

    public int $leaderTakeovers = 0;

    public ?float $lastLeaderGapSeconds = null;

    public ?float $lastTakeoverSeconds = null;

    public ?string $lastError = null;
}
