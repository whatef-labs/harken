<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Wire;

/**
 * Rules for MQTT UTF-8 encoded strings (§1.5.4): well-formed UTF-8, no
 * U+0000 and at most 65 535 bytes.
 *
 * @internal
 */
final class Utf8
{
    public const int MAX_BYTES = 65535;

    /**
     * Returns why the string is not a valid MQTT string, or null when it is.
     */
    public static function problem(string $value): ?string
    {
        if (strlen($value) > self::MAX_BYTES) {
            return 'is longer than 65535 bytes';
        }

        if (preg_match('//u', $value) !== 1) {
            return 'is not valid UTF-8';
        }

        if (str_contains($value, "\0")) {
            return 'contains U+0000';
        }

        return null;
    }
}
