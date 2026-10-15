<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Will report every runtime's heartbeat (connection state, last loop tick,
 * subscriptions, memory) from the cache, and exit non-zero when one is stale
 * so it can serve as a container health check.
 *
 * Skeleton.
 */
#[AsCommand(name: 'harken:status')]
final class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'harken:status';

    /** @var string */
    protected $description = 'Show the health of running Harken runtimes';

    public function handle(): int
    {
        $this->components->warn('harken:status is not implemented yet.');

        return self::FAILURE;
    }
}
