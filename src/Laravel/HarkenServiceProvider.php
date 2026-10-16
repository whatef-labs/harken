<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Whatef\Harken\Laravel\Console\RestartCommand;
use Whatef\Harken\Laravel\Console\StartCommand;
use Whatef\Harken\Laravel\Console\StatusCommand;
use Whatef\Harken\Laravel\Publishing\StreamsDelegatePublisher;
use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Observability\Metrics;
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

        $this->app->singleton(Metrics::class, NullMetrics::class);
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
            );
        };

        $this->app->bind(DispatchObserver::class, $observer);
        $this->app->bind(NotificationObserver::class, $observer);
    }

    public function boot(): void
    {
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
