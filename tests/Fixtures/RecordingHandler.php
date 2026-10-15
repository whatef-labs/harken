<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Fixtures;

use Whatef\Harken\Runtime\Message;

final class RecordingHandler
{
    /** @var list<Message> */
    public static array $messages = [];

    public function __invoke(Message $message): void
    {
        self::$messages[] = $message;
    }
}
