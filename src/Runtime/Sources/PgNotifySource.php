<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use LogicException;
use React\EventLoop\LoopInterface;
use SensitiveParameter;

/**
 * PostgreSQL LISTEN/NOTIFY as a loop event source.
 *
 * Planned: with ext-pgsql, the connection socket from pg_socket() is watched
 * with addReadStream() and notifications are drained with pg_get_notify().
 * Without ext-pgsql it falls back to polling on a timer.
 *
 * Skeleton: shaped by spike 04.
 */
final class PgNotifySource implements Source
{
    /**
     * @param  list<string>  $channels
     * @param  callable(string $channel, string $payload): void  $onNotify
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $dsn,
        public readonly array $channels,
        private readonly mixed $onNotify,
    ) {}

    public function name(): string
    {
        return 'pgsql:'.implode(',', $this->channels);
    }

    public function start(LoopInterface $loop): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }

    public function stop(): void
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }
}
