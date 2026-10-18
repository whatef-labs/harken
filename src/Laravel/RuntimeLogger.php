<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Psr\Log\LoggerInterface;
use Throwable;
use Whatef\Harken\Events\ConnectionStateChanged;
use Whatef\Harken\Laravel\Console\RestartCommand;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Leadership\LeadershipObserver;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Outbox\OutboxObserver;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Runtime\RuntimeObserver;
use Whatef\Harken\Runtime\RuntimeState;
use Whatef\Harken\Runtime\RuntimeStats;

/**
 * Logs the runtime's life cycle, connection, election and outbox events,
 * keeps their counters and metrics, writes the status heartbeat and reads
 * the restart flag. Credentials and payloads are never logged here.
 *
 * $console, when given, also receives the important lines (start, leader
 * changes, stop) for the `harken:start` output.
 */
final class RuntimeLogger implements LeadershipObserver, OutboxObserver, RuntimeObserver
{
    private ?Runtime $runtime = null;

    private ?Throwable $failure = null;

    /** @var array<string, mixed>|null */
    private ?array $lastStatus = null;

    /** @var array<string, int> Successful connects per connection. */
    private array $connects = [];

    /** @var array<string, array{string, int, int}> Connection => [instance, received, published] at the last heartbeat. */
    private array $counted = [];

    /** @var (Closure(string, string): void)|null */
    private readonly ?Closure $console;

    /**
     * @param  (callable(string $level, string $line): void)|null  $console
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Metrics $metrics,
        public readonly RuntimeStats $stats,
        private readonly HealthStore $health,
        private readonly Cache $restartCache,
        ?callable $console = null,
        private readonly ?Events $events = null,
    ) {
        $this->console = $console === null ? null : $console(...);
    }

    /**
     * The runtime that a fatal election or source problem should stop.
     */
    public function attach(Runtime $runtime): void
    {
        $this->runtime = $runtime;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    public function connectionState(string $connection, string $clientId, ConnectionState $from, ConnectionState $to, ?Throwable $error = null): void
    {
        $labels = ['connection' => $connection];
        $this->metrics->gauge('harken_connected', $to === ConnectionState::Connected ? 1 : 0, $labels);

        try {
            $this->events?->dispatch(new ConnectionStateChanged($connection, $clientId, $from, $to, $to === ConnectionState::Connected ? null : $error));
        } catch (Throwable $e) {
            $this->error($e);
        }

        if ($to !== ConnectionState::Connected) {
            return;
        }

        $this->connects[$connection] = ($this->connects[$connection] ?? 0) + 1;
        $this->logger->info("Harken {$connection} connection is connected.", $labels);

        if ($this->connects[$connection] > 1) {
            $this->metrics->increment('harken_reconnects_total', 1, $labels);
        }
    }

    public function connectionError(string $connection, Throwable $error): void
    {
        $this->logger->warning("Harken {$connection} connection: {$error->getMessage()}", ['connection' => $connection]);
    }

    public function started(Runtime $runtime): void
    {
        $this->metrics->gauge('harken_start_time_seconds', round(microtime(true), 3));
        $this->logger->info('Harken runtime started.', [
            'connection' => $runtime->connection->options->name,
            'client_id' => $runtime->connection->options->clientId,
            'protocol' => $runtime->connection->options->protocol->value,
        ]);
    }

    public function error(Throwable $error): void
    {
        $this->stats->lastError = $error->getMessage();
        $this->logger->warning("Harken: {$error->getMessage()}", ['exception' => $error::class]);
    }

    public function failed(Throwable $error): void
    {
        $this->failure ??= $error;
        $this->stats->lastError = $error->getMessage();

        $runtime = $this->runtime;

        if ($runtime === null || $runtime->state() !== RuntimeState::Running) {
            return;
        }

        // The election or a source failed: stop the runtime, which reports
        // the failure back here.
        if ($runtime->failure() === null) {
            $runtime->fail($error);

            return;
        }

        $this->logger->error("Harken cannot continue: {$error->getMessage()}", ['exception' => $error::class]);
        $this->console('error', $error->getMessage());
    }

    public function stopping(string $reason): void
    {
        $this->logger->info("Harken runtime is stopping ({$reason}).");
        $this->console('info', "Stopping ({$reason}).");
    }

    public function shutdownStepAbandoned(string $step, ?Throwable $error): void
    {
        $this->logger->warning($error === null
            ? "Harken shutdown step [{$step}] did not finish within the grace period."
            : "Harken shutdown step [{$step}] failed: {$error->getMessage()}");
    }

    public function stopped(string $reason, int $exitCode): void
    {
        $this->logger->info("Harken runtime stopped ({$reason}).", ['exit_code' => $exitCode]);

        if ($this->lastStatus !== null) {
            $this->health->forget($this->lastStatus);
        }
    }

    public function heartbeat(array $status): void
    {
        $this->lastStatus = $status;
        $this->health->write($status);

        $this->metrics->gauge('harken_memory_bytes', memory_get_usage(true));
        $this->metrics->gauge('harken_loop_lag_seconds', is_numeric($status['loop_lag_ms'] ?? null) ? (float) $status['loop_lag_ms'] / 1000 : 0.0);

        foreach ($this->stats->outboxLengths as $stream => $length) {
            $this->metrics->gauge('harken_outbox_waiting', $length, ['stream' => $stream]);
        }

        $this->countMessages($status);
    }

    /**
     * Brings the received and published counters up to the connections'
     * running totals in a status. The heartbeat does it every status
     * interval; a metrics reporter can do it right before it reads them.
     *
     * @param  array<string, mixed>  $status
     */
    public function countMessages(array $status): void
    {
        $mqtt = is_array($status['mqtt'] ?? null) ? $status['mqtt'] : [];
        $this->count('main', (string) json_encode($status['started_at'] ?? null), $mqtt);
        $leader = is_array($status['leader'] ?? null) ? $status['leader'] : [];

        if (is_array($leader['mqtt'] ?? null)) {
            // A new term opens a new leader connection, whose counters start at zero.
            $this->count('leader', (string) json_encode($leader['terms'] ?? null), $leader['mqtt']);
        }
    }

    /**
     * Turns a connection's running totals into counter increments.
     *
     * @param  array<array-key, mixed>  $mqtt
     */
    private function count(string $connection, string $instance, array $mqtt): void
    {
        $received = is_int($mqtt['received'] ?? null) ? $mqtt['received'] : 0;
        $published = is_int($mqtt['published'] ?? null) ? $mqtt['published'] : 0;
        [$lastInstance, $lastReceived, $lastPublished] = $this->counted[$connection] ?? [$instance, 0, 0];

        if ($lastInstance !== $instance) {
            $lastReceived = $lastPublished = 0;
        }

        $labels = ['connection' => $connection];
        $this->metrics->increment('harken_messages_received_total', max(0, $received - $lastReceived), $labels);

        if ($connection === 'main') {
            $this->metrics->increment('harken_messages_published_total', max(0, $published - $lastPublished), $labels);
        }

        $this->counted[$connection] = [$instance, $received, $published];
    }

    public function restartRequested(float $startedAt): bool
    {
        $requested = $this->restartCache->get(RestartCommand::CACHE_KEY);

        return is_numeric($requested) && (float) $requested >= $startedAt;
    }

    public function acquired(int $term, ?float $vacantSeconds): void
    {
        $this->stats->leaderTakeovers++;
        $this->metrics->gauge('harken_leader', 1);
        $this->metrics->increment('harken_leader_takeovers_total');
        $this->logger->info('Harken became the leader replica.', ['term' => $term, 'vacant_seconds' => $vacantSeconds]);
        $this->console('info', 'Leading: subscribing leader routes.');
    }

    public function subscribed(int $term, float $takeoverSeconds, ?float $gapSeconds): void
    {
        $this->stats->lastTakeoverSeconds = round($takeoverSeconds, 3);
        $this->stats->lastLeaderGapSeconds = $gapSeconds === null ? null : round($gapSeconds, 3);
        $this->metrics->observe('harken_leader_takeover_seconds', $takeoverSeconds);
        $this->metrics->gauge('harken_leader_last_takeover_seconds', $takeoverSeconds);

        if ($gapSeconds !== null) {
            $this->metrics->observe('harken_leader_gap_seconds', $gapSeconds);
            $this->metrics->gauge('harken_leader_last_gap_seconds', $gapSeconds);
        }

        $this->logger->info('Harken leader is receiving.', [
            'term' => $term,
            'takeover_seconds' => round($takeoverSeconds, 3),
            'gap_seconds' => $gapSeconds === null ? null : round($gapSeconds, 3),
        ]);
    }

    public function lost(int $term, string $reason): void
    {
        $this->metrics->gauge('harken_leader', 0);
        $this->logger->warning("Harken stopped leading: {$reason}.", ['term' => $term]);
        $this->console('warn', "Standing by: {$reason}.");
    }

    public function published(string $stream, OutboxMessage $message, ?float $latencyMs): void
    {
        $this->stats->outboxPublished++;
        $this->metrics->increment('harken_outbox_published_total', 1, ['stream' => $stream]);

        if ($latencyMs !== null) {
            $this->metrics->observe('harken_outbox_latency_seconds', $latencyMs / 1000, ['stream' => $stream]);
        }
    }

    public function dropped(string $stream, string $id, ?OutboxMessage $message, Throwable $reason): void
    {
        $this->stats->outboxDropped++;
        $this->metrics->increment('harken_outbox_dropped_total', 1, ['stream' => $stream]);
        $this->logger->error("Harken dropped outbox entry {$id}: {$reason->getMessage()}", [
            'stream' => $stream,
            'topic' => $message?->topic,
        ]);
    }

    private function console(string $level, string $line): void
    {
        if ($this->console !== null) {
            ($this->console)($level, $line);
        }
    }
}
