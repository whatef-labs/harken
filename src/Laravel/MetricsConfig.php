<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Reads `harken.metrics.driver`: one driver, several separated by commas
 * ("prometheus,log"), or a list. They all read the same in-memory registry,
 * so any of them can run together. "null" or nothing turns metrics off.
 */
final class MetricsConfig
{
    public const array DRIVERS = ['prometheus', 'log', 'pulse'];

    /**
     * @return list<string> The drivers to run, each once, in the order given.
     *
     * @throws InvalidArgumentException for an unknown driver.
     */
    public static function drivers(Config $config): array
    {
        $value = $config->get('harken.metrics.driver', 'null');
        $names = match (true) {
            $value === null => [],
            is_array($value) => $value,
            default => explode(',', is_scalar($value) ? (string) $value : '?'),
        };
        $drivers = [];

        foreach ($names as $name) {
            $name = is_string($name) ? trim($name) : '?';

            if ($name === '' || $name === 'null') {
                continue;
            }

            if (! in_array($name, self::DRIVERS, true)) {
                throw new InvalidArgumentException("Unknown Harken metrics driver [{$name}]; use null, prometheus, log or pulse, or several of them separated by commas.");
            }

            $drivers[$name] = $name;
        }

        return array_values($drivers);
    }
}
