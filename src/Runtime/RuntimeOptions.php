<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

final readonly class RuntimeOptions
{
    /**
     * @param  float  $graceSeconds  Time a graceful stop may take.
     * @param  float|null  $maxSeconds  Stop gracefully after running this long.
     * @param  int|null  $maxMemoryMb  Stop gracefully once memory use passes this.
     * @param  float  $statusInterval  How often the status heartbeat is reported.
     * @param  float  $restartCheckInterval  How often a restart request is looked for.
     * @param  bool  $handleSignals  Stop gracefully on SIGTERM / SIGINT (needs ext-pcntl).
     */
    public function __construct(
        public float $graceSeconds = 10.0,
        public ?float $maxSeconds = null,
        public ?int $maxMemoryMb = null,
        public float $statusInterval = 5.0,
        public float $restartCheckInterval = 5.0,
        public bool $handleSignals = true,
    ) {}
}
