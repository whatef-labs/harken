<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Reconnect;

use Closure;
use InvalidArgumentException;

/**
 * Exponential backoff with jitter.
 *
 * The un-jittered delay is `min(max, initial * multiplier^(attempt - 1))`.
 * Jitter then subtracts a random share of it: with `jitter = 0.5` the delay
 * falls in [base * 0.5, base]; with `jitter = 1.0` it is "full jitter" in
 * [0, base]. The delay never exceeds `max`, which keeps a fleet of replicas
 * from reconnecting in lockstep after a broker restart.
 */
final readonly class ExponentialBackoff implements Backoff
{
    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param  (callable(): float)|null  $random  Returns a float in [0, 1). Injected for tests.
     */
    public function __construct(
        public float $initial = 1.0,
        public float $max = 60.0,
        public float $multiplier = 2.0,
        public float $jitter = 0.5,
        public ?int $maxAttempts = null,
        ?callable $random = null,
    ) {
        if ($initial <= 0 || $max < $initial || $multiplier < 1) {
            throw new InvalidArgumentException('Backoff needs 0 < initial <= max and multiplier >= 1.');
        }

        if ($jitter < 0 || $jitter > 1) {
            throw new InvalidArgumentException('Backoff jitter must be between 0 and 1.');
        }

        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw new InvalidArgumentException('Backoff max attempts must be at least 1, or null for unlimited.');
        }

        $this->random = $random === null
            ? static fn (): float => mt_rand() / (mt_getrandmax() + 1)
            : $random(...);
    }

    /**
     * @param  array<string, mixed>  $config  The `reconnect` block of a connection in config/harken.php.
     */
    public static function fromArray(array $config): self
    {
        $float = static fn (string $key, float $default): float => is_numeric($config[$key] ?? null) ? (float) $config[$key] : $default;

        return new self(
            initial: $float('initial_delay', 1.0),
            max: $float('max_delay', 60.0),
            multiplier: $float('multiplier', 2.0),
            jitter: $float('jitter', 0.5),
            maxAttempts: is_numeric($config['max_attempts'] ?? null) ? (int) $config['max_attempts'] : null,
        );
    }

    public function delay(int $attempt): float
    {
        $exponent = max(0, $attempt - 1);
        $base = min($this->max, $this->initial * $this->multiplier ** $exponent);

        return $base - $base * $this->jitter * ($this->random)();
    }

    public function allows(int $attempt): bool
    {
        return $this->maxAttempts === null || $attempt < $this->maxAttempts;
    }
}
