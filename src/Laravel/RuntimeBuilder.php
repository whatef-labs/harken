<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Whatef\Harken\Laravel\Publishing\ReportingPublisher;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Leadership\Leadership;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Outbox\RedisStreamsOutbox;
use Whatef\Harken\Runtime\Routing\Dispatcher;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Routing\Router;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Runtime\RuntimeOptions;
use Whatef\Harken\Runtime\RuntimeStats;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\NotificationDispatcher;
use Whatef\Harken\Runtime\Sources\NotificationInvoker;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Sources\NotificationRouter;
use Whatef\Harken\Runtime\Sources\PgNotifySource;
use Whatef\Harken\Transport\Transport;

/**
 * Builds a runtime for one `harken.connections.*` entry from the Laravel
 * configuration, the registered routes and the container.
 *
 * - The main connection (a client id per replica) publishes and receives
 *   the shared and broadcast routes.
 * - Leader routes and leader NOTIFY channels follow the election: the leader
 *   opens a second connection with the fixed leader client id and listens
 *   on PostgreSQL while its lease holds.
 * - Broadcast NOTIFY channels are listened to by every replica.
 * - The outbox publishes what the application queued outside the runtime.
 */
final class RuntimeBuilder
{
    /** @var (Closure(string, Packet): void)|null */
    private ?Closure $trace = null;

    /** @var (Closure(string, string): void)|null */
    private ?Closure $console = null;

    private ?PgNotifySource $leaderSource = null;

    public function __construct(
        private readonly Container $app,
        private readonly Config $config,
        private readonly HarkenManager $harken,
    ) {}

    /**
     * @param  callable(string $line, Packet $packet): void  $trace  Receives "main out", "leader in", ... with each packet.
     */
    public function trace(callable $trace): self
    {
        $this->trace = $trace(...);

        return $this;
    }

    /**
     * @param  callable(string $level, string $line): void  $console
     */
    public function console(callable $console): self
    {
        $this->console = $console(...);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $connectionConfig
     */
    public function build(
        string $name,
        array $connectionConfig,
        LoopInterface $loop,
        Transport $transport,
        ?int $maxMemoryMb = null,
        ?float $maxSeconds = null,
    ): Runtime {
        $options = ConnectionConfig::options($name, $connectionConfig);
        $router = $this->harken->router();
        $notifications = $this->harken->notifications();
        $stats = new RuntimeStats;
        $logger = $this->logger($stats);
        $invoker = $this->app->make(HandlerInvoker::class);
        $observer = new CountingDispatchObserver(
            $this->app->make(DispatchObserver::class),
            $this->app->make(NotificationObserver::class),
            $stats,
        );
        $threshold = $this->float('harken.handlers.slow_threshold_ms', 100.0);

        $replicaRoutes = $router->only(Distribution::Shared, Distribution::Broadcast);
        $connection = $this->connection('main', $options, $replicaRoutes, $transport, $loop, $logger);
        $leadership = $this->leadership($connectionConfig, $options, $router->only(Distribution::Leader), $notifications->only(Distribution::Leader), $transport, $loop, $logger, $invoker, $observer, $threshold);
        $broadcast = $notifications->only(Distribution::Broadcast);

        $runtime = new Runtime(
            loop: $loop,
            connection: $connection,
            dispatcher: new Dispatcher($replicaRoutes, $invoker, $observer, $threshold),
            scheduler: $this->harken->scheduler(),
            observer: $logger,
            options: new RuntimeOptions(
                graceSeconds: $this->float('harken.shutdown.grace', 10.0),
                maxSeconds: $maxSeconds,
                maxMemoryMb: $maxMemoryMb,
                statusInterval: $this->float('harken.health.interval', 5.0),
                restartCheckInterval: $this->float('harken.restart_check_interval', 5.0),
            ),
            leadership: $leadership,
            outbox: $this->outbox($options, $logger),
            sources: $broadcast->routes() === [] ? [] : [$this->source($broadcast, $loop, $logger, $invoker, $observer, $threshold)],
            stats: $stats,
        );

        $logger->attach($runtime);
        $this->harken->usePublisher(new ReportingPublisher($connection, $this->logChannel()));

        return $runtime;
    }

    private function connection(string $role, ConnectionOptions $options, Router $routes, Transport $transport, LoopInterface $loop, RuntimeLogger $logger): Connection
    {
        $connection = new Connection($options, $transport, $options->protocol->codec(), $loop);
        $connection->subscribe($routes->subscriptions());
        $connection->onError(fn ($e) => $logger->connectionError($role, $e));
        $connection->onStateChange(fn (ConnectionState $from, ConnectionState $to) => $logger->connectionState($role, $from, $to));

        if ($this->trace !== null) {
            $trace = $this->trace;
            $connection->trace(static fn (string $direction, Packet $packet) => $trace("{$role} {$direction}", $packet));
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $connectionConfig
     */
    private function leadership(
        array $connectionConfig,
        ConnectionOptions $options,
        Router $routes,
        NotificationRouter $notifications,
        Transport $transport,
        LoopInterface $loop,
        RuntimeLogger $logger,
        HandlerInvoker $invoker,
        CountingDispatchObserver $observer,
        float $threshold,
    ): ?Leadership {
        if ($routes->routes() === [] && $notifications->routes() === []) {
            return null;
        }

        $leader = LeaderConfig::options($connectionConfig);
        $dispatcher = new Dispatcher($routes, $invoker, $observer, $threshold);
        $connect = $routes->routes() === [] ? null : function () use ($leader, $options, $routes, $transport, $loop, $logger, $dispatcher): Connection {
            $connection = $this->connection('leader', $options->withSession($leader->clientId, false, $leader->sessionExpiry), $routes, $transport, $loop, $logger);
            $connection->onMessage(function (Message $message) use ($dispatcher): void {
                $dispatcher->dispatch($message);
            });

            return $connection;
        };

        $leadership = new Leadership(
            $loop,
            LeaderConfig::lock($leader, new StoreConfig($this->config), $loop),
            $connect,
            $logger,
            $leader->lease,
            $leader->retryInterval,
        );

        if ($notifications->routes() !== []) {
            // One source per term: it listens only while this replica leads.
            $leadership->onTerm(function () use ($notifications, $loop, $logger, $invoker, $observer, $threshold, $leadership): void {
                $this->leaderSource?->stop();
                $this->leaderSource = $this->source($notifications, $loop, $logger, $invoker, $observer, $threshold, $leadership->holdsLease(...));
                $this->leaderSource->start($loop);
            }, function (): void {
                $this->leaderSource?->stop();
                $this->leaderSource = null;
            });
        }

        return $leadership;
    }

    /**
     * @param  (callable(): bool)|null  $guard
     */
    private function source(
        NotificationRouter $routes,
        LoopInterface $loop,
        RuntimeLogger $logger,
        HandlerInvoker $invoker,
        CountingDispatchObserver $observer,
        float $threshold,
        ?callable $guard = null,
    ): PgNotifySource {
        $connection = $this->config->get('harken.sources.pgsql.connection', 'pgsql');
        $notifier = $this->app->make(NotificationInvoker::class);
        $dispatcher = new NotificationDispatcher($routes, $notifier, $observer, $threshold);
        $channels = $routes->channels();

        return new PgNotifySource(
            (new StoreConfig($this->config))->postgres(is_string($connection) ? $connection : 'pgsql'),
            $channels === [] ? ['harken'] : $channels,
            static fn (Notification $notification) => $dispatcher->dispatch($notification),
            pollIntervalMs: (int) $this->float('harken.sources.pgsql.poll_interval_ms', 10.0),
            onError: $logger->error(...),
            guard: $guard,
        );
    }

    private function outbox(ConnectionOptions $options, RuntimeLogger $logger): ?RedisStreamsOutbox
    {
        if (! $this->config->get('harken.outbox.enabled', true)) {
            return null;
        }

        $redis = $this->config->get('harken.outbox.redis', 'default');
        $endpoint = (new StoreConfig($this->config))->redis(is_string($redis) ? $redis : 'default');
        $streams = [];

        foreach (['urgent', 'normal'] as $priority) {
            $stream = $this->config->get("harken.outbox.streams.{$priority}", "harken:outbox:{$priority}");
            $streams[] = $endpoint->prefix.(is_string($stream) ? $stream : "harken:outbox:{$priority}");
        }

        $group = $this->config->get('harken.outbox.group', 'harken');
        $maxRate = $this->config->get('harken.outbox.max_rate');

        return new RedisStreamsOutbox(
            endpoint: $endpoint,
            streams: $streams,
            group: is_string($group) ? $group : 'harken',
            consumer: $options->clientId,
            blockMs: (int) $this->float('harken.outbox.block_ms', 1000.0),
            batchSize: (int) $this->float('harken.outbox.batch', 100.0),
            claimIdleMs: (int) $this->float('harken.outbox.claim_idle_ms', 30000.0),
            maxRate: is_numeric($maxRate) && (float) $maxRate > 0 ? (float) $maxRate : null,
            observer: $logger,
        );
    }

    private function logChannel(): LoggerInterface
    {
        $channel = $this->config->get('harken.logging.channel');

        return is_string($channel) && $channel !== ''
            ? $this->app->make(LogManager::class)->channel($channel)
            : $this->app->make(LoggerInterface::class);
    }

    private function logger(RuntimeStats $stats): RuntimeLogger
    {
        $store = $this->config->get('harken.health.cache_store');
        $cache = $this->app->make(CacheFactory::class);

        return new RuntimeLogger(
            $this->logChannel(),
            $this->app->make(Metrics::class),
            $stats,
            new HealthStore($cache->store(is_string($store) && $store !== '' ? $store : null), (int) $this->float('harken.health.stale_after', 30.0)),
            $cache->store(),
            $this->console,
        );
    }

    private function float(string $key, float $default): float
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }
}
