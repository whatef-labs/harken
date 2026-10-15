<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

use InvalidArgumentException;

/**
 * A route pattern such as `sensors/{id}/temperature`.
 *
 * - `{name}` matches exactly one level and captures it as a parameter. It is
 *   subscribed as `+`.
 * - `+` matches one level without capturing it.
 * - `#` matches all remaining levels, including none (`a/#` matches `a`). It
 *   must be the last level.
 * - A topic starting with `$` never matches a wildcard first level (MQTT
 *   3.1.1 section 4.7.2).
 */
final class TopicPattern
{
    private const int LITERAL = 0;

    private const int PARAM = 1;

    private const int SINGLE = 2;

    private const int MULTI = 3;

    /** @var list<array{int, string}> Level kind and literal value or parameter name. */
    private array $levels = [];

    private string $filter;

    private bool $literal = true;

    public function __construct(public readonly string $pattern)
    {
        if ($pattern === '') {
            throw new InvalidArgumentException('A topic pattern cannot be empty.');
        }

        if (str_starts_with($pattern, '$share/')) {
            throw new InvalidArgumentException("Pattern [{$pattern}] must not include \$share/; use ->shared() instead.");
        }

        if (str_contains($pattern, "\0")) {
            throw new InvalidArgumentException('A topic pattern cannot contain NUL characters.');
        }

        $parts = explode('/', $pattern);
        $last = count($parts) - 1;
        $names = [];
        $filter = [];

        foreach ($parts as $i => $part) {
            if ($part === '#') {
                if ($i !== $last) {
                    throw new InvalidArgumentException("Pattern [{$pattern}]: '#' is only allowed as the last level.");
                }
                $this->levels[] = [self::MULTI, ''];
                $filter[] = '#';
                $this->literal = false;

                continue;
            }

            if ($part === '+') {
                $this->levels[] = [self::SINGLE, ''];
                $filter[] = '+';
                $this->literal = false;

                continue;
            }

            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $part, $m) === 1) {
                if (isset($names[$m[1]])) {
                    throw new InvalidArgumentException("Pattern [{$pattern}]: parameter {{$m[1]}} is used twice.");
                }
                $names[$m[1]] = true;
                $this->levels[] = [self::PARAM, $m[1]];
                $filter[] = '+';
                $this->literal = false;

                continue;
            }

            if (strpbrk($part, '+#{}') !== false) {
                throw new InvalidArgumentException(
                    "Pattern [{$pattern}]: level [{$part}] mixes text with a wildcard or parameter; they must fill a whole level."
                );
            }

            $this->levels[] = [self::LITERAL, $part];
            $filter[] = $part;
        }

        $this->filter = implode('/', $filter);
    }

    /**
     * The MQTT topic filter to subscribe to.
     */
    public function filter(): string
    {
        return $this->filter;
    }

    /**
     * @return list<string>
     */
    public function parameterNames(): array
    {
        $names = [];
        foreach ($this->levels as [$kind, $value]) {
            if ($kind === self::PARAM) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Matches a concrete topic. Returns the captured parameters, or null when
     * the topic does not match.
     *
     * @return array<string, string>|null
     */
    public function match(string $topic): ?array
    {
        if ($this->literal) {
            return $topic === $this->pattern ? [] : null;
        }

        $parts = explode('/', $topic);
        $count = count($parts);
        $params = [];

        if (str_starts_with($topic, '$') && $this->levels[0][0] !== self::LITERAL) {
            return null;
        }

        foreach ($this->levels as $i => [$kind, $value]) {
            if ($kind === self::MULTI) {
                return $params;
            }

            if ($i >= $count) {
                return null;
            }

            if ($kind === self::LITERAL) {
                if ($parts[$i] !== $value) {
                    return null;
                }
            } elseif ($kind === self::PARAM) {
                $params[$value] = $parts[$i];
            }
        }

        return $count === count($this->levels) ? $params : null;
    }
}
