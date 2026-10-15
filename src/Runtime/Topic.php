<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime;

use InvalidArgumentException;

final class Topic
{
    /**
     * Validates a topic and QoS for PUBLISH.
     *
     * @throws InvalidArgumentException
     */
    public static function assertPublishable(string $topic, int $qos): void
    {
        if ($topic === '' || strpbrk($topic, "+#\0") !== false) {
            throw new InvalidArgumentException("Cannot publish to [{$topic}]: topics must be non-empty and free of wildcards.");
        }

        if (strlen($topic) > 65535) {
            throw new InvalidArgumentException('Cannot publish: the topic is longer than 65535 bytes.');
        }

        if ($qos !== 0 && $qos !== 1) {
            throw new InvalidArgumentException("Cannot publish with QoS [{$qos}]: only QoS 0 and 1 are supported.");
        }
    }
}
