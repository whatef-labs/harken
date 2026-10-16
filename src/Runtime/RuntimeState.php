<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

enum RuntimeState: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
}
