<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Whatef\Harken\Laravel\ConnectionConfig;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\LeaderConfig;
use Whatef\Harken\Laravel\RuntimeBuilder;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Runtime\LoopFactory;
use Whatef\Harken\Runtime\Routing\Distribution;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Transport\Transport;

#[AsCommand(name: 'harken:start')]
final class StartCommand extends Command
{
    /** @var string */
    protected $signature = 'harken:start
        {--connection= : The broker connection to use}
        {--debug : Print every MQTT packet}
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

        /** @var array<string, mixed> $connection */
        try {
            // Validate early; the runtime builds its own options (and client id).
            ConnectionConfig::options($name, $connection);
            $leaderRoutes = $harken->router()->only(Distribution::Leader)->routes() !== [] || $harken->notifications()->only(Distribution::Leader)->routes() !== [];
            $leader = $leaderRoutes ? LeaderConfig::options($connection) : null;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warnings($harken, $config);

        $builder = new RuntimeBuilder($container, $config, $harken);
        $builder->console(function (string $level, string $line): void {
            match ($level) {
                'error' => $this->components->error($line),
                'warn' => $this->components->warn($line),
                default => $this->components->info($line),
            };
        });

        if ($this->option('debug')) {
            $builder->trace(fn (string $where, Packet $packet) => $this->line(sprintf(
                '<fg=gray>%s</> %-12s %s',
                date('H:i:s'),
                $where,
                (new ReflectionClass($packet))->getShortName(),
            )));
        }

        $loop = $container->make(LoopInterface::class);

        try {
            $runtime = $builder->build(
                $name,
                $connection,
                $loop,
                $container->make(Transport::class),
                is_numeric($this->option('memory')) ? (int) $this->option('memory') : null,
                is_numeric($this->option('max-time')) ? (float) $this->option('max-time') : null,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $options = $runtime->connection->options;
        $this->components->info(sprintf(
            'Starting Harken on %s as [%s] (MQTT %s, %s).',
            $options->endpoint->uri(),
            $options->clientId,
            $options->protocol->value,
            LoopFactory::backend($loop),
        ));

        if ($leader !== null) {
            $this->components->twoColumnDetail('Leader routes', sprintf('lock %s, client id [%s], lease %s s', $leader->lock, $leader->clientId, $leader->lease));
        }

        if ($this->option('debug')) {
            $this->routes($harken);
        }

        $exitCode = $runtime->run();
        $failure = $runtime->failure();
        $hint = $failure === null ? null : ConnectionConfig::hint($failure, $options);

        if ($hint !== null) {
            $this->components->warn($hint);
        }

        return $exitCode;
    }

    private function warnings(HarkenManager $harken, Config $config): void
    {
        $router = $harken->router();

        if ($router->routes() === [] && $harken->notifications()->routes() === []) {
            $this->components->warn('No routes are registered; nothing will be subscribed.');
        }

        if (($router->hasQueuedRoutes() || $harken->notifications()->hasQueuedRoutes()) && $config->get('queue.default') === 'sync') {
            $this->components->warn(
                'Queued routes run inline because the default queue connection is [sync]; they will block the event loop.'
            );
        }
    }

    private function routes(HarkenManager $harken): void
    {
        $this->table(
            ['Pattern', 'Subscription', 'Distribution', 'QoS', 'Handler', 'Queued'],
            array_map(static fn (Route $route): array => [
                $route->pattern->pattern,
                $route->subscriptionFilter(),
                $route->getDistribution()->value,
                $route->getQos(),
                $route->handlerName(),
                $route->isQueued() ? 'yes' : 'no',
            ], $harken->router()->routes()),
        );
    }
}
