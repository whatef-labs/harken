<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use Throwable;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Leadership\Leadership;
use Whatef\Harken\Runtime\Outbox\Outbox;
use Whatef\Harken\Runtime\Outbox\RedisStreamsOutbox;
use Whatef\Harken\Runtime\Routing\Dispatcher;
use Whatef\Harken\Runtime\Shutdown\ShutdownSequence;
use Whatef\Harken\Runtime\Sources\Source;
use Whatef\Harken\Runtime\Timers\Scheduler;

use function React\Promise\resolve;

/**
 * Runs the connection, leader election, outbox, sources and timers on one
 * loop until it is stopped. Framework-agnostic; the Laravel command builds
 * it.
 *
 * A graceful stop (signal, restart request, memory or time limit) runs in
 * this order, within the grace period:
 *
 *  1. stop timers and sources; hold inbound messages (unacknowledged QoS 1
 *     messages are delivered again after the connection closes)
 *  2. stop leading: disconnect the leader connection, release the lock
 *  3. let the outbox finish the entries in flight
 *  4. wait for the remaining publishes to be acknowledged
 *  5. send DISCONNECT, then stop the loop
 */
final class Runtime
{
    private RuntimeState $state = RuntimeState::Idle;

    private int $exitCode = 0;

    private ?Throwable $failure = null;

    private float $startedAt = 0.0;

    private ?float $lastHeartbeat = null;

    private float $loopLagMs = 0.0;

    /** @var list<TimerInterface> */
    private array $timers = [];

    /** @var (Closure(int): void)|null */
    private ?Closure $signalHandler = null;

    private bool $signalReceived = false;

    /**
     * @param  list<Source>  $sources  Sources every replica runs (leader-only sources follow the election).
     */
    public function __construct(
        public readonly LoopInterface $loop,
        public readonly Connection $connection,
        private readonly Dispatcher $dispatcher,
        private readonly Scheduler $scheduler,
        private readonly RuntimeObserver $observer,
        public readonly RuntimeOptions $options = new RuntimeOptions,
        public readonly ?Leadership $leadership = null,
        public readonly ?Outbox $outbox = null,
        public readonly array $sources = [],
        public readonly RuntimeStats $stats = new RuntimeStats,
    ) {}

    public function state(): RuntimeState
    {
        return $this->state;
    }

    /**
     * Starts everything and blocks until the runtime stops. Returns the
     * process exit code: 0 after a graceful stop, 1 after a failure.
     */
    public function run(): int
    {
        $this->start();
        $this->loop->run();

        return $this->exitCode;
    }

    /**
     * Starts everything without running the loop (run() does both).
     */
    public function start(): void
    {
        if ($this->state !== RuntimeState::Idle) {
            return;
        }

        $this->state = RuntimeState::Running;
        $this->startedAt = microtime(true);

        $this->connection->onMessage(function (Message $message): void {
            $this->dispatcher->dispatch($message);
        });
        $this->connection->onStateChange(function (ConnectionState $from, ConnectionState $to): void {
            if ($to === ConnectionState::Connected && $this->state === RuntimeState::Running) {
                $this->heartbeat();
            }
        });
        $this->connection->onClose(function (?Throwable $reason): void {
            if ($reason !== null) {
                $this->fail($reason);
            }
        });
        $this->connection->connect()->then(null, fn (Throwable $e) => $this->fail($e));

        $this->leadership?->start();
        $this->outbox?->start($this->loop, $this->connection);

        foreach ($this->sources as $source) {
            $source->start($this->loop);
        }

        $this->scheduler->start($this->loop, fn (Throwable $e) => $this->observer->error($e));
        $this->installSignals();
        $this->startTimers();
        $this->observer->started($this);
        $this->heartbeat();
    }

    /**
     * Stops gracefully. Safe to call more than once; the first reason wins.
     *
     * @return PromiseInterface<null>
     */
    public function stop(string $reason, int $exitCode = 0): PromiseInterface
    {
        if ($this->state !== RuntimeState::Running) {
            return resolve(null);
        }

        $this->state = RuntimeState::Stopping;
        $this->exitCode = $exitCode;
        $this->observer->stopping($reason);

        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }

        $this->timers = [];
        $this->scheduler->stop();
        // A last status, so the counters it carries include the final seconds
        // before the sources (the metrics reporters among them) stop.
        $this->heartbeat();

        foreach ($this->sources as $source) {
            $source->stop();
        }

        $this->connection->holdInbound();

        $steps = [
            'leadership' => fn (): PromiseInterface => $this->leadership?->stop() ?? resolve(null),
            'outbox' => fn (float $left): PromiseInterface => $this->outbox?->stop($left) ?? resolve(null),
            'publishes' => fn (): PromiseInterface => $this->connection->whenIdle(),
            'disconnect' => fn (): PromiseInterface => $this->connection->disconnect(),
        ];

        return (new ShutdownSequence($this->options->graceSeconds))
            ->run($this->loop, $steps, fn (string $step, ?Throwable $error) => $this->observer->shutdownStepAbandoned($step, $error))
            ->then(function () use ($reason): null {
                $this->state = RuntimeState::Stopped;
                $this->observer->stopped($reason, $this->exitCode);
                $this->removeSignals();
                $this->loop->stop();

                return null;
            });
    }

    /**
     * What made the runtime stop with exit code 1, if anything.
     */
    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    /**
     * A snapshot for health checks and `harken:status`.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $connection = $this->connection;
        $leadership = $this->leadership;
        $leader = $leadership?->connection();

        return [
            'state' => $this->state->value,
            'pid' => getmypid() ?: null,
            'host' => gethostname() ?: null,
            'connection' => $connection->options->name,
            'client_id' => $connection->options->clientId,
            'protocol' => $connection->options->protocol->value,
            'loop' => LoopFactory::backend($this->loop),
            'started_at' => $this->startedAt,
            'heartbeat_at' => microtime(true),
            'loop_lag_ms' => round($this->loopLagMs, 2),
            'memory_mb' => round(memory_get_usage(true) / 1048576, 1),
            'mqtt' => [
                'state' => $connection->state()->value,
                'connected_at' => $connection->connectedAt(),
                'disconnected_at' => $connection->disconnectedAt(),
                'reconnects' => $connection->reconnects(),
                'received' => $connection->receivedCount(),
                'published' => $connection->publishedCount(),
                'in_flight' => $connection->inFlightCount(),
                'queued' => $connection->queuedCount(),
            ],
            'leader' => $leadership === null ? null : [
                'state' => $leadership->state()->value,
                'lock' => $leadership->lock->name(),
                'terms' => $leadership->terms(),
                'takeovers' => $this->stats->leaderTakeovers,
                'last_gap_seconds' => $this->stats->lastLeaderGapSeconds,
                'last_takeover_seconds' => $this->stats->lastTakeoverSeconds,
                'mqtt' => $leader === null ? null : [
                    'client_id' => $leader->options->clientId,
                    'state' => $leader->state()->value,
                    'received' => $leader->receivedCount(),
                ],
            ],
            'outbox' => $this->outbox === null ? null : [
                'in_flight' => $this->outbox instanceof RedisStreamsOutbox ? $this->outbox->inFlightCount() : null,
                'published' => $this->stats->outboxPublished,
                'dropped' => $this->stats->outboxDropped,
                'expired' => $this->stats->outboxExpired,
                'trimmed' => $this->stats->outboxTrimmed,
                'waiting' => $this->stats->outboxLengths,
            ],
            'handlers' => [
                'failed' => $this->stats->handlerFailures,
                'slow' => $this->stats->slowHandlers,
                'unmatched' => $this->stats->unmatched,
            ],
            'last_error' => $this->stats->lastError,
        ];
    }

    /**
     * Stops with exit code 1 after a problem the runtime cannot recover from.
     */
    public function fail(Throwable $error): void
    {
        // The observer may report the failure back here while it is handled.
        if ($this->state !== RuntimeState::Running || $this->failure !== null) {
            return;
        }

        $this->failure = $error;
        $this->stats->lastError = $error->getMessage();
        $this->observer->failed($error);
        $this->stop('failure', 1);
    }

    private function startTimers(): void
    {
        $this->timers[] = $this->loop->addPeriodicTimer($this->options->statusInterval, $this->heartbeat(...));
        $this->timers[] = $this->loop->addPeriodicTimer($this->options->restartCheckInterval, function (): void {
            try {
                if ($this->observer->restartRequested($this->startedAt)) {
                    $this->stop('restart requested');
                }
            } catch (Throwable $e) {
                $this->observer->error($e);
            }
        });

        if ($this->options->maxSeconds !== null) {
            $this->timers[] = $this->loop->addTimer($this->options->maxSeconds, fn () => $this->stop('time limit reached'));
        }

        if ($this->options->maxMemoryMb !== null) {
            $limit = $this->options->maxMemoryMb * 1048576;
            $this->timers[] = $this->loop->addPeriodicTimer(1.0, function () use ($limit): void {
                if (memory_get_usage(true) >= $limit) {
                    $this->stop('memory limit reached');
                }
            });
        }
    }

    private function heartbeat(): void
    {
        $now = hrtime(true) / 1e9;

        if ($this->lastHeartbeat !== null) {
            $this->loopLagMs = max(0.0, ($now - $this->lastHeartbeat - $this->options->statusInterval) * 1000);
        }

        $this->lastHeartbeat = $now;

        if ($this->outbox instanceof RedisStreamsOutbox) {
            $this->outbox->lengths()->then(function (array $lengths): void {
                $this->stats->outboxLengths = $lengths;
            }, static function (): void {
                // Reported by the outbox observer.
            });
            $this->outbox->collectTrimmed();
        }

        try {
            $this->observer->heartbeat($this->status());
        } catch (Throwable $e) {
            $this->observer->error($e);
        }
    }

    /**
     * SIGTERM, SIGINT and SIGTSTP (ext-pcntl), or Ctrl+C and Ctrl+Break on
     * Windows, stop the runtime gracefully. The same signal again while it is
     * stopping ends the process at once.
     */
    private function installSignals(): void
    {
        if (! $this->options->handleSignals) {
            return;
        }

        if (extension_loaded('pcntl') && defined('SIGTERM')) {
            $handler = $this->signalHandler = function (int $signal): void {
                $this->signalled(match ($signal) {
                    SIGINT => 'SIGINT',
                    SIGTSTP => 'SIGTSTP',
                    default => 'SIGTERM',
                });
            };

            foreach ([SIGTERM, SIGINT, SIGTSTP] as $signal) {
                $this->loop->addSignal($signal, $handler);
            }

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            $this->signalHandler = function (int $event): void {
                $this->signalled($event === PHP_WINDOWS_EVENT_CTRL_BREAK ? 'CTRL+BREAK' : 'CTRL+C');
            };
            sapi_windows_set_ctrl_handler($this->signalHandler);
        }
    }

    /**
     * Signal handlers run asynchronously (ReactPHP enables async signals),
     * possibly in the middle of a handler. The stop is therefore only
     * scheduled here and starts once the current callback has returned.
     */
    private function signalled(string $signal): void
    {
        if ($this->signalReceived) {
            $this->observer->stopped("{$signal} again while stopping", 1);

            exit(1);
        }

        $this->signalReceived = true;
        $this->loop->futureTick(fn () => $this->stop($signal));
    }

    private function removeSignals(): void
    {
        if ($this->signalHandler === null) {
            return;
        }

        if (extension_loaded('pcntl') && defined('SIGTERM')) {
            foreach ([SIGTERM, SIGINT, SIGTSTP] as $signal) {
                $this->loop->removeSignal($signal, $this->signalHandler);
            }
        } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler($this->signalHandler, false);
        }

        $this->signalHandler = null;
    }
}
