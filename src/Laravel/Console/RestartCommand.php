<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Asks every running runtime to stop gracefully so the process manager
 * starts it again with fresh code. Each runtime compares this flag with its
 * own start time every `harken.restart_check_interval` seconds.
 */
#[AsCommand(name: 'harken:restart')]
final class RestartCommand extends Command
{
    use InteractsWithTime;

    public const string CACHE_KEY = 'harken:restart';

    /** @var string */
    protected $signature = 'harken:restart';

    /** @var string */
    protected $description = 'Signal running Harken runtimes to restart';

    public function handle(Cache $cache): int
    {
        $cache->forever(self::CACHE_KEY, $this->currentTime());

        $this->components->info('Broadcasting Harken restart signal.');

        return self::SUCCESS;
    }
}
