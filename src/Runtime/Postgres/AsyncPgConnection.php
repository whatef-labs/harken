<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Postgres;

use Closure;
use PgSql\Connection as PgSqlConnection;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use SensitiveParameter;
use SplQueue;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A PostgreSQL connection driven by the event loop (ext-pgsql): the
 * connection is opened with PGSQL_CONNECT_ASYNC and queries are sent with
 * pg_send_query(), so neither blocks the loop while waiting for the server.
 * NOTIFY messages that arrive on the connection are passed to a listener.
 *
 * One query runs at a time; others wait in order. A query that gets no
 * answer within the timeout marks the connection dead, which also catches
 * a server that vanished without closing the socket.
 *
 * A failing socket is removed from the loop inside the callback that
 * noticed it; a dead stream left in StreamSelectLoop stops the whole loop.
 * libpq closes the socket as soon as it reads the end of the connection,
 * possibly while parsing messages (for example the FATAL message of an
 * idle-session timeout), so the connection status is checked after every
 * call into libpq, not only when a read fails.
 */
final class AsyncPgConnection
{
    private ?PgSqlConnection $pg = null;

    /** @var resource|null */
    private $readStream = null;

    /** @var resource|null */
    private $writeStream = null;

    /** @var Deferred<null>|null */
    private ?Deferred $connecting = null;

    /** @var SplQueue<array{string, Deferred<list<array<string, string|null>>>}> */
    private SplQueue $queue;

    /** @var Deferred<list<array<string, string|null>>>|null */
    private ?Deferred $current = null;

    private ?TimerInterface $timeout = null;

    /** @var (Closure(string, string): void)|null */
    private ?Closure $onNotify = null;

    /** @var (Closure(Throwable): void)|null */
    private ?Closure $onClose = null;

    public function __construct(
        private readonly LoopInterface $loop,
        #[SensitiveParameter] private readonly string $dsn,
        public readonly float $timeoutSeconds = 5.0,
    ) {
        if (! extension_loaded('pgsql')) {
            throw new RuntimeException('The PostgreSQL connection needs the pgsql extension (ext-pgsql).');
        }

        $this->queue = new SplQueue;
    }

    /**
     * @param  callable(string $channel, string $payload): void  $listener
     */
    public function onNotify(callable $listener): void
    {
        $this->onNotify = $listener(...);
    }

    /**
     * Called when an open connection fails. Pending queries are rejected.
     *
     * @param  callable(Throwable): void  $listener
     */
    public function onClose(callable $listener): void
    {
        $this->onClose = $listener(...);
    }

    public function isConnected(): bool
    {
        return $this->pg !== null && $this->connecting === null;
    }

    /**
     * @return PromiseInterface<null>
     */
    public function connect(): PromiseInterface
    {
        if ($this->connecting !== null) {
            return $this->connecting->promise();
        }

        if ($this->pg !== null) {
            return resolve(null);
        }

        $pg = @pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW | PGSQL_CONNECT_ASYNC);

        if ($pg === false) {
            return reject(new RuntimeException('Cannot start a PostgreSQL connection: check the connection settings.'));
        }

        $this->pg = $pg;
        $deferred = $this->connecting = new Deferred;
        $this->timeout = $this->loop->addTimer($this->timeoutSeconds, function (): void {
            $this->timeout = null;
            $this->fail(new RuntimeException(sprintf('PostgreSQL did not accept the connection within %s s.', $this->timeoutSeconds)));
        });
        $this->poll();

        return $deferred->promise();
    }

    /**
     * Runs one statement. Resolves with the rows of its last result.
     *
     * @return PromiseInterface<list<array<string, string|null>>>
     */
    public function query(string $sql): PromiseInterface
    {
        /** @var Deferred<list<array<string, string|null>>> $deferred */
        $deferred = new Deferred;
        $this->queue->enqueue([$sql, $deferred]);

        $this->connect()->then(fn () => $this->next(), static function (): void {
            // fail() has rejected the queued query already.
        });

        return $deferred->promise();
    }

    public function close(): void
    {
        $this->shutdown(new RuntimeException('The PostgreSQL connection was closed.'), notify: false);
    }

    private function poll(): void
    {
        $pg = $this->pg;

        if ($pg === null) {
            return;
        }

        $this->unwatch();
        $status = pg_connect_poll($pg);

        if ($status === PGSQL_POLLING_OK) {
            $this->cancelTimeout();
            $deferred = $this->connecting;
            $this->connecting = null;
            $this->watchRead();
            $deferred?->resolve(null);

            return;
        }

        if ($status === PGSQL_POLLING_FAILED) {
            $this->fail(new RuntimeException('PostgreSQL refused the connection: '.trim(pg_last_error($pg))));

            return;
        }

        $socket = @pg_socket($pg);

        if ($socket === false) {
            $this->fail(new RuntimeException('PostgreSQL connection has no socket.'));

            return;
        }

        if ($status === PGSQL_POLLING_WRITING) {
            $this->writeStream = $socket;
            $this->loop->addWriteStream($socket, fn () => $this->poll());
        } else {
            $this->readStream = $socket;
            $this->loop->addReadStream($socket, fn () => $this->poll());
        }
    }

    private function watchRead(): void
    {
        $pg = $this->pg;
        $socket = $pg === null ? false : @pg_socket($pg);

        if ($socket === false) {
            $this->fail(new RuntimeException('PostgreSQL connection has no socket.'));

            return;
        }

        $this->readStream = $socket;
        $this->loop->addReadStream($socket, fn () => $this->readable());
    }

    private function next(): void
    {
        $pg = $this->pg;

        if ($pg === null || $this->connecting !== null || $this->current !== null || $this->queue->isEmpty()) {
            return;
        }

        [$sql, $deferred] = $this->queue->dequeue();
        $this->current = $deferred;

        if (@pg_send_query($pg, $sql) !== true || ! self::alive($pg)) {
            $this->fail(new RuntimeException('Cannot send a query to PostgreSQL: '.trim(pg_last_error($pg))));

            return;
        }

        $this->timeout = $this->loop->addTimer($this->timeoutSeconds, function (): void {
            $this->timeout = null;
            $this->fail(new RuntimeException(sprintf('PostgreSQL did not answer within %s s.', $this->timeoutSeconds)));
        });
    }

    private function readable(): void
    {
        $pg = $this->pg;

        if ($pg === null) {
            return;
        }

        $this->drain($pg);

        if ($this->pg === $pg && ! self::alive($pg)) {
            $this->fail(new RuntimeException('The PostgreSQL connection was lost.'));
        }
    }

    private function drain(PgSqlConnection $pg): void
    {
        if (! @pg_consume_input($pg)) {
            $this->fail(new RuntimeException('The PostgreSQL connection was lost.'));

            return;
        }

        if (! self::alive($pg)) {
            return;
        }

        while (($notify = pg_get_notify($pg, PGSQL_ASSOC)) !== false) {
            if ($this->onNotify !== null) {
                ($this->onNotify)((string) $notify['message'], (string) ($notify['payload'] ?? ''));
            }

            if ($this->pg !== $pg) {
                return;
            }
        }

        $deferred = $this->current;

        if ($deferred === null || pg_connection_busy($pg) || ! self::alive($pg)) {
            return;
        }

        $rows = [];
        $error = null;

        while (($result = pg_get_result($pg)) !== false) {
            $status = pg_result_status($result);

            if ($status === PGSQL_FATAL_ERROR || $status === PGSQL_BAD_RESPONSE || $status === PGSQL_NONFATAL_ERROR) {
                $error = trim((string) pg_result_error($result));
            } else {
                /** @var list<array<string, string|null>> $rows */
                $rows = pg_fetch_all($result);
            }
        }

        $this->cancelTimeout();
        $this->current = null;

        $error === null
            ? $deferred->resolve($rows)
            : $deferred->reject(new RuntimeException("PostgreSQL query failed: {$error}"));

        $this->next();
    }

    private static function alive(PgSqlConnection $pg): bool
    {
        return pg_connection_status($pg) === PGSQL_CONNECTION_OK;
    }

    private function fail(Throwable $reason): void
    {
        $this->shutdown($reason, notify: true);
    }

    private function shutdown(Throwable $reason, bool $notify): void
    {
        $wasOpen = $this->pg !== null;
        $this->unwatch();
        $this->cancelTimeout();

        if ($this->pg !== null) {
            @pg_close($this->pg);
            $this->pg = null;
        }

        $connecting = $this->connecting;
        $this->connecting = null;
        $connecting?->reject($reason);

        $current = $this->current;
        $this->current = null;
        $current?->reject($reason);

        while (! $this->queue->isEmpty()) {
            $this->queue->dequeue()[1]->reject($reason);
        }

        if ($notify && $wasOpen && $connecting === null && $this->onClose !== null) {
            ($this->onClose)($reason);
        }
    }

    private function unwatch(): void
    {
        if ($this->readStream !== null) {
            $this->loop->removeReadStream($this->readStream);
            $this->readStream = null;
        }

        if ($this->writeStream !== null) {
            $this->loop->removeWriteStream($this->writeStream);
            $this->writeStream = null;
        }
    }

    private function cancelTimeout(): void
    {
        if ($this->timeout !== null) {
            $this->loop->cancelTimer($this->timeout);
            $this->timeout = null;
        }
    }
}
