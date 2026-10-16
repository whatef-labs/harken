<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

enum LeadershipState: string
{
    case Idle = 'idle';
    case Standby = 'standby';
    case Leading = 'leading';
    case Stopped = 'stopped';
}
