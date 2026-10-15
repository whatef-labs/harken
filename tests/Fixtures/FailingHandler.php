<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Fixtures;

use RuntimeException;
use Whatef\Harken\Runtime\Message;

final class FailingHandler
{
    public function __invoke(Message $message): void
    {
        throw new RuntimeException("Handler failed for {$message->topic}.");
    }
}
