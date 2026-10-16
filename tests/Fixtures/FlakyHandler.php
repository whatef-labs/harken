<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Fixtures;

use RuntimeException;
use Whatef\Harken\Runtime\Message;

/**
 * Fails the first $failures calls, then records the messages it handles.
 */
final class FlakyHandler
{
    public static int $failures = 1;

    /** @var list<Message> */
    public static array $handled = [];

    public function __invoke(Message $message): void
    {
        if (self::$failures > 0) {
            self::$failures--;

            throw new RuntimeException('Not yet.');
        }

        self::$handled[] = $message;
    }
}
