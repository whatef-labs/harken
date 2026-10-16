<?php

declare(strict_types=1);

namespace Whatef\Harken\Facades;

use Illuminate\Support\Facades\Facade;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\Testing\HarkenFake;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;

/**
 * @method static \Whatef\Harken\Runtime\Routing\Route topic(string $pattern, string|callable $handler)
 * @method static \React\Promise\PromiseInterface<mixed> publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?\Whatef\Harken\Protocol\Properties $properties = null)
 * @method static \React\Promise\PromiseInterface<mixed> clearRetained(string $topic)
 * @method static \Whatef\Harken\Laravel\UrgentPublisher urgent()
 * @method static \Whatef\Harken\Runtime\Sources\NotificationRoute listen(string $channel, string|callable $handler)
 * @method static void every(int|float $seconds, callable $callback)
 * @method static void simulate(string $topic, string $payload, int $qos = 0, bool $retain = false, ?\Whatef\Harken\Protocol\Properties $properties = null)
 * @method static void assertPublished(string $topic, ?callable $callback = null)
 * @method static void assertNotPublished(string $topic, ?callable $callback = null)
 * @method static void assertNothingPublished()
 *
 * @see HarkenManager
 * @see HarkenFake
 */
final class Harken extends Facade
{
    /**
     * Replaces the broker with an in-memory fake for tests.
     */
    public static function fake(): HarkenFake
    {
        $root = self::getFacadeRoot();

        if ($root instanceof HarkenFake) {
            return $root;
        }

        /** @var HarkenManager $root */
        $fake = new HarkenFake($root, app(HandlerInvoker::class));

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return HarkenManager::class;
    }
}
