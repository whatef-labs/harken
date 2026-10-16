<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Sources;

use Closure;
use PDO;
use Pdo\Pgsql;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use Whatef\Harken\Runtime\Postgres\AsyncPgConnection;
use Whatef\Harken\Runtime\Reconnect\Backoff;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;

use function React\Promise\all;

/**
 * PostgreSQL LISTEN/NOTIFY as a loop event source, on a connection of its
 * own (never shared with Eloquent).
 *
 * - With ext-pgsql, the connection socket is watched by the loop and a
 *   notification reaches its handler as soon as it arrives.
 * - Without it, Pdo\Pgsql::getNotify() is polled every $pollIntervalMs
 *   milliseconds. This is the only place the runtime waits on a fixed
 *   interval; connecting and the health query block the loop briefly.
 *
 * After every (re)connect the source LISTENs on each channel and hands the
 * handlers one Notification with `resync` set, because notifications sent
 * while it was not listening are lost. A health query every
 * $healthCheckSeconds catches a connection that died without closing. A
 * lost connection is retried with backoff.
 */
final class PgNotifySource implements Source
{
    private ?LoopInterface $loop = null;

    private bool $running = false;

    private bool $listening = false;

    private int $failures = 0;

    private ?AsyncPgConnection $pg = null;

    private ?Pgsql $pdo = null;

    private ?TimerInterface $pollTimer = null;

    private ?TimerInterface $healthTimer = null;

    private ?TimerInterface $retryTimer = null;

    /** @var Closure(Notification): void */
    private readonly Closure $onNotify;

    /** @var (Closure(Throwable): void)|null */
    private readonly ?Closure $onError;

    /** @var (Closure(): bool)|null */
    private readonly ?Closure $guard;

    public readonly bool $usesExtension;

    /**
     * @param  string  $dsn  libpq connection string ("host=... dbname=... user=...").
     * @param  non-empty-list<string>  $channels
     * @param  callable(Notification): void  $onNotify
     * @param  (callable(Throwable): void)|null  $onError  Connection problems; the source retries by itself.
     * @param  (callable(): bool)|null  $guard  Notifications are dropped while it returns false.
     * @param  bool|null  $useExtension  null: ext-pgsql when loaded, otherwise PDO polling.
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $dsn,
        public readonly array $channels,
        callable $onNotify,
        public readonly int $pollIntervalMs = 10,
        private readonly Backoff $backoff = new ExponentialBackoff(initial: 1.0, max: 30.0),
        ?callable $onError = null,
        ?callable $guard = null,
        public readonly float $healthCheckSeconds = 30.0,
        ?bool $useExtension = null,
    ) {
        $this->onNotify = $onNotify(...);
        $this->onError = $onError === null ? null : $onError(...);
        $this->guard = $guard === null ? null : $guard(...);
        $this->usesExtension = $useExtension ?? extension_loaded('pgsql');

        if (! $this->usesExtension && ! class_exists(Pgsql::class)) {
            throw new RuntimeException('PostgreSQL notifications need ext-pgsql or pdo_pgsql.');
        }
    }

    public function name(): string
    {
        return sprintf('pgsql:%s (%s)', implode(',', $this->channels), $this->usesExtension ? 'ext-pgsql' : "pdo, polled every {$this->pollIntervalMs} ms");
    }

    public function isListening(): bool
    {
        return $this->listening;
    }

    public function start(LoopInterface $loop): void
    {
        if ($this->running) {
            return;
        }

        $this->loop = $loop;
        $this->running = true;
        $this->connect();
    }

    public function stop(): void
    {
        $this->running = false;
        $this->listening = false;
        $this->cancelTimers();
        $this->pg?->close();
        $this->pg = null;
        $this->pdo = null;
    }

    private function connect(): void
    {
        $this->retryTimer = null;

        if (! $this->running) {
            return;
        }

        $this->usesExtension ? $this->connectAsync() : $this->connectPdo();
    }

    private function connectAsync(): void
    {
        $loop = $this->loop;

        if ($loop === null) {
            return;
        }

        $pg = $this->pg = new AsyncPgConnection($loop, $this->dsn);
        $pg->onNotify(fn (string $channel, string $payload) => $this->deliver(new Notification($channel, $payload)));
        $pg->onClose(function (Throwable $e) use ($pg): void {
            if ($pg === $this->pg) {
                $this->lost($e);
            }
        });

        $listens = array_map(fn (string $channel) => $pg->query('LISTEN '.self::quote($channel)), $this->channels);

        all($listens)->then(function () use ($pg, $loop): void {
            if ($pg !== $this->pg) {
                return;
            }

            $this->listening();
            $this->healthTimer = $loop->addPeriodicTimer($this->healthCheckSeconds, static function () use ($pg): void {
                $pg->query('SELECT 1')->then(null, static function (): void {
                    // A failed health query closes the connection; onClose reconnects.
                });
            });
        }, function (Throwable $e) use ($pg): void {
            if ($pg === $this->pg) {
                $this->lost($e);
            }
        });
    }

    private function connectPdo(): void
    {
        $loop = $this->loop;

        if ($loop === null) {
            return;
        }

        try {
            $pdo = new Pgsql('pgsql:'.$this->dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            foreach ($this->channels as $channel) {
                $pdo->exec('LISTEN '.self::quote($channel));
            }
        } catch (Throwable $e) {
            $this->lost($e);

            return;
        }

        $this->pdo = $pdo;
        $this->pollTimer = $loop->addPeriodicTimer($this->pollIntervalMs / 1000, $this->poll(...));
        $this->healthTimer = $loop->addPeriodicTimer($this->healthCheckSeconds, function (): void {
            try {
                $this->pdo?->query('SELECT 1');
            } catch (Throwable $e) {
                $this->lost($e);
            }
        });
        $this->listening();
    }

    private function poll(): void
    {
        $pdo = $this->pdo;

        if ($pdo === null) {
            return;
        }

        try {
            while (($notify = $pdo->getNotify(PDO::FETCH_ASSOC, 0)) !== false) {
                $this->deliver(new Notification((string) ($notify['message'] ?? ''), (string) ($notify['payload'] ?? '')));

                if ($this->pdo !== $pdo) {
                    return;
                }
            }
        } catch (Throwable $e) {
            $this->lost($e);
        }
    }

    private function listening(): void
    {
        $this->listening = true;
        $this->failures = 0;

        foreach ($this->channels as $channel) {
            $this->deliver(new Notification($channel, '', true));
        }
    }

    private function deliver(Notification $notification): void
    {
        if (! $this->running || ($this->guard !== null && ! ($this->guard)())) {
            return;
        }

        try {
            ($this->onNotify)($notification);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    private function lost(Throwable $reason): void
    {
        if (! $this->running) {
            return;
        }

        $this->listening = false;
        $this->cancelTimers();
        $pg = $this->pg;
        $this->pg = null;
        $this->pdo = null;
        $pg?->close();
        $this->failures++;
        $this->report(new RuntimeException("PostgreSQL notifications stopped: {$reason->getMessage()}", 0, $reason));

        $this->retryTimer = $this->loop?->addTimer($this->backoff->delay($this->failures), $this->connect(...));
    }

    private function report(Throwable $error): void
    {
        if ($this->onError !== null) {
            ($this->onError)($error);
        }
    }

    private function cancelTimers(): void
    {
        foreach ([$this->pollTimer, $this->healthTimer, $this->retryTimer] as $timer) {
            if ($timer !== null) {
                $this->loop?->cancelTimer($timer);
            }
        }

        $this->pollTimer = $this->healthTimer = $this->retryTimer = null;
    }

    private static function quote(string $channel): string
    {
        return '"'.str_replace('"', '""', $channel).'"';
    }
}
