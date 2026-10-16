<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\E2E\Load;

use Whatef\Harken\Runtime\Message;

/**
 * A handler that blocks for 50 ms, standing in for several database
 * round trips or an external call.
 */
final class SlowReport
{
    public function __invoke(Message $message): void
    {
        usleep(50_000);
    }
}
