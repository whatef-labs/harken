<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Outbox;

use Throwable;

interface OutboxObserver
{
    /**
     * The broker acknowledged the message (or took it, for QoS 0) and the
     * entry was removed from its stream.
     *
     * @param  float|null  $latencyMs  From XADD to the acknowledgement, when the entry carries its enqueue time.
     */
    public function published(string $stream, OutboxMessage $message, ?float $latencyMs): void;

    /**
     * The entry was removed without being published: it is malformed, or the
     * broker refused the message for good (for example "not authorized").
     */
    public function dropped(string $stream, string $id, ?OutboxMessage $message, Throwable $reason): void;

    /**
     * The message expiry interval ran out while the entry waited, so it was
     * removed without being published.
     */
    public function expired(string $stream, OutboxMessage $message): void;

    /**
     * The application trimmed the $count oldest entries of the stream to
     * keep it under outbox.max_length.
     */
    public function trimmed(string $stream, int $count): void;

    /**
     * A problem the outbox recovers from (Redis unreachable, connection to
     * the broker closed). Affected entries stay pending and are retried.
     */
    public function error(Throwable $error): void;
}
