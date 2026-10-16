<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Pulse\Pulse;
use Livewire\LivewireManager;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Whatef\Harken\Laravel\Console\RestartCommand;
use Whatef\Harken\Laravel\Console\StartCommand;
use Whatef\Harken\Laravel\Console\StatusCommand;
use Whatef\Harken\Laravel\Publishing\StreamsDelegatePublisher;
use Whatef\Harken\Laravel\Pulse\Livewire\Handlers;
use Whatef\Harken\Laravel\Pulse\Livewire\Messages;
use Whatef\Harken\Laravel\Pulse\Livewire\Replicas;
use Whatef\Harken\Laravel\Queue\FailedMessageStore;
use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Observability\MetricsRegistry;
use Whatef\Harken\Observability\NullMetrics;
use Whatef\Harken\Runtime\LoopFactory;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Routing\Router;
use Whatef\Harken\Runtime\Sources\NotificationInvoker;
use Whatef\Harken\Runtime\Sources\NotificationObserver;
use Whatef\Harken\Runtime\Timers\Scheduler;
use Whatef\Harken\Transport\ReactSocketTransport;
use Whatef\Harken\Transport\Transport;

final class HarkenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/harken.php', 'harken');

        $this->app->singleton(HarkenManager::class, static function (Container $app): HarkenManager {
            $config = $app->make(Config::class);
            $routes = $config->get('harken.routes');
            $redis = $config->get('harken.outbox.redis', 'default');
            $stream = $config->get('harken.outbox.streams.normal', 'harken:outbox:normal');
            $urgent = $config->get('harken.outbox.streams.urgent', 'harken:outbox:urgent');

            return new HarkenManager(
                new Router,
                new Scheduler,
                new StreamsDelegatePublisher(
                    $app->make(RedisFactory::class),
                    new StoreConfig($config),
                    is_string($redis) ? $redis : 'default',
                    is_string($stream) ? $stream : 'harken:outbox:normal',
                    is_string($urgent) ? $urgent : 'harken:outbox:urgent',
                ),
                is_string($routes) ? $routes : null,
            );
        });

        $this->app->singleton(MetricsRegistry::class);
        $this->app->singleton(Metrics::class, static function (Container $app): Metrics {
            $driver = $app->make(Config::class)->get('harken.metrics.driver', 'null');

            return match ($driver) {
                'prometheus', 'log' => $app->make(MetricsRegistry::class),
                'pulse' => class_exists(Pulse::class)
                    ? $app->make(MetricsRegistry::class)
                    : throw new InvalidArgumentException('The Harken metrics driver [pulse] needs Laravel Pulse: composer require laravel/pulse.'),
                null, 'null', '' => new NullMetrics,
                default => throw new InvalidArgumentException('Unknown Harken metrics driver ['.(is_scalar($driver) ? $driver : '?').']; use null, prometheus, log or pulse.'),
            };
        });
        $this->app->bind(HandlerInvoker::class, ContainerHandlerInvoker::class);
        $this->app->bind(NotificationInvoker::class, ContainerHandlerInvoker::class);
        $this->app->bind(LoopInterface::class, static fn (): LoopInterface => LoopFactory::get());
        $this->app->bind(Transport::class, static fn (Container $app): Transport => new ReactSocketTransport($app->make(LoopInterface::class)));

        $observer = static function (Container $app): LoggingDispatchObserver {
            $config = $app->make(Config::class);
            $channel = $config->get('harken.logging.channel');

            return new LoggingDispatchObserver(
                is_string($channel) && $channel !== ''
                    ? $app->make(LogManager::class)->channel($channel)
                    : $app->make(LoggerInterface::class),
                new LogContext((bool) $config->get('harken.logging.log_payloads', false)),
                $app->make(Metrics::class),
                $app->make(ExceptionHandler::class),
                $app->make(Events::class),
                $config->get('harken.handlers.failed_jobs', false) ? $app->make(FailedMessageStore::class) : null,
            );
        };

        $this->app->bind(FailedMessageStore::class, fn (): FailedMessageStore => new FailedMessageStore(
            $this->app->make('queue.failer'),
            $this->app,
            $this->app->make(Config::class),
        ));

        $this->app->bind(DispatchObserver::class, $observer);
        $this->app->bind(NotificationObserver::class, $observer);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'harken');

        if (class_exists(Pulse::class) && class_exists(LivewireManager::class)) {
            $this->callAfterResolving('livewire', static function (LivewireManager $livewire): void {
                $livewire->component('harken.messages', Messages::class);
                $livewire->component('harken.replicas', Replicas::class);
                $livewire->component('harken.handlers', Handlers::class);
            });
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            StartCommand::class,
            RestartCommand::class,
            StatusCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../../config/harken.php' => $this->app->configPath('harken.php'),
        ], 'harken-config');

        $this->publishes([
            __DIR__.'/../../stubs/routes.stub' => $this->app->basePath('routes/harken.php'),
        ], 'harken-routes');
    }
}
