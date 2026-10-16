<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Whatef\Harken\Laravel\ConnectionConfig;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\LoopFactory;
use Whatef\Harken\Runtime\Routing\Dispatcher;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Runtime\Shutdown\ShutdownSequence;
use Whatef\Harken\Transport\ReactSocketTransport;

#[AsCommand(name: 'harken:start')]
final class StartCommand extends Command
{
    /** @var string */
    protected $signature = 'harken:start
        {--connection= : The broker connection to use}
        {--debug : Print packet-level logs}
        {--memory= : Stop gracefully after using this many megabytes}
        {--max-time= : Stop gracefully after running this many seconds}';

    /** @var string */
    protected $description = 'Start the Harken MQTT runtime';

    public function handle(HarkenManager $harken, Config $config, Container $container): int
    {
        $name = $this->option('connection') ?: $config->get('harken.default');
        $connection = $config->get("harken.connections.{$name}");

        if (! is_string($name) || ! is_array($connection)) {
            $this->components->error('Harken connection ['.(is_string($name) ? $name : '').'] is not configured.');

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $connection */
            $options = ConnectionConfig::options($name, $connection);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $router = $harken->router();

        if ($router->routes() === []) {
            $this->components->warn('No routes are registered; nothing will be subscribed.');
        }

        if ($router->hasQueuedRoutes() && $config->get('queue.default') === 'sync') {
            $this->components->warn(
                'Queued routes run inline because the default queue connection is [sync]; they will block the event loop.'
            );
        }

        $this->components->info(sprintf(
            'Starting Harken on %s as [%s] (MQTT %s, %s).',
            $options->endpoint->uri(),
            $options->clientId,
            $options->protocol->value,
            LoopFactory::backend(),
        ));

        if ($this->option('debug')) {
            $this->table(
                ['Pattern', 'Subscription', 'QoS', 'Handler', 'Queued'],
                array_map(static fn ($route): array => [
                    $route->pattern->pattern,
                    $route->subscriptionFilter(),
                    $route->getQos(),
                    $route->handlerName(),
                    $route->isQueued() ? 'yes' : 'no',
                ], $router->routes()),
            );
        }

        return $this->runtime($options, $harken, $config, $container)->run();
    }

    private function runtime(ConnectionOptions $options, HarkenManager $harken, Config $config, Container $container): Runtime
    {
        $loop = LoopFactory::get();
        $connection = new Connection($options, new ReactSocketTransport($loop), $options->protocol->codec(), $loop);
        $threshold = $config->get('harken.handlers.slow_threshold_ms', 100);
        $grace = $config->get('harken.shutdown.grace', 10);

        return new Runtime(
            loop: $loop,
            connection: $connection,
            router: $harken->router(),
            dispatcher: new Dispatcher(
                $harken->router(),
                $container->make(HandlerInvoker::class),
                $container->make(DispatchObserver::class),
                is_numeric($threshold) ? (float) $threshold : 100.0,
            ),
            scheduler: $harken->scheduler(),
            shutdown: new ShutdownSequence(is_numeric($grace) ? (float) $grace : 10.0),
        );
    }
}
