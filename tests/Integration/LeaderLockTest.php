<?php

declare(strict_types=1);

/*
 * Leader locks against real Redis and PostgreSQL servers.
 *
 *   HARKEN_TEST_REDIS_HOST=127.0.0.1 \
 *   HARKEN_TEST_PG_DSN="host=127.0.0.1 port=5432 dbname=harken user=harken password=harken" \
 *   vendor/bin/pest --group=integration
 */

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Whatef\Harken\Runtime\Leadership\LeaderLock;
use Whatef\Harken\Runtime\Leadership\LeaseGrant;
use Whatef\Harken\Runtime\Leadership\PgAdvisoryLeaderLock;
use Whatef\Harken\Runtime\Leadership\RedisLeaderLock;
use Whatef\Harken\Runtime\Postgres\AsyncPgConnection;
use Whatef\Harken\Runtime\Redis\RedisClient;
use Whatef\Harken\Runtime\Redis\RedisEndpoint;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\Timer\timeout;

// Close the locks' connections even when a test fails half-way.
afterEach(function () {
    foreach (lock_opened() as $lock) {
        $lock->close();
    }

    lock_opened(reset: true);
});

/**
 * @return list<LeaderLock>
 */
function lock_opened(?LeaderLock $lock = null, bool $reset = false): array
{
    static $locks = [];

    if ($reset) {
        $locks = [];
    }

    if ($lock !== null) {
        $locks[] = $lock;
    }

    return $locks;
}

/**
 * Two locks on one key, as two replicas would hold them.
 *
 * @return array{LeaderLock, LeaderLock}
 */
function lock_pair(string $backend): array
{
    $key = 'harken-it:leader:'.bin2hex(random_bytes(4));

    if ($backend === 'redis') {
        $host = getenv('HARKEN_TEST_REDIS_HOST');

        if (! $host) {
            test()->markTestSkipped('Set HARKEN_TEST_REDIS_HOST to run Redis lock tests.');
        }

        $endpoint = new RedisEndpoint($host, (int) (getenv('HARKEN_TEST_REDIS_PORT') ?: 6379));

        return [
            lock_opened(new RedisLeaderLock(new RedisClient($endpoint, Loop::get()), $key))[0],
            lock_opened(new RedisLeaderLock(new RedisClient($endpoint, Loop::get()), $key))[1],
        ];
    }

    $dsn = getenv('HARKEN_TEST_PG_DSN');

    if (! $dsn || ! extension_loaded('pgsql')) {
        test()->markTestSkipped('Set HARKEN_TEST_PG_DSN (and load ext-pgsql) to run PostgreSQL lock tests.');
    }

    return [
        lock_opened(new PgAdvisoryLeaderLock(new AsyncPgConnection(Loop::get(), $dsn), $key))[0],
        lock_opened(new PgAdvisoryLeaderLock(new AsyncPgConnection(Loop::get(), $dsn), $key))[1],
    ];
}

/**
 * @template T
 *
 * @param  PromiseInterface<T>  $promise
 * @return T
 */
function lock_await(PromiseInterface $promise): mixed
{
    return await(timeout($promise, 5.0));
}

dataset('backends', ['redis', 'pgsql']);

it('lets one owner hold the lock at a time', function (string $backend) {
    [$a, $b] = lock_pair($backend);

    expect(lock_await($a->acquire('a', 5.0)))->toBeInstanceOf(LeaseGrant::class)
        ->and(lock_await($b->acquire('b', 5.0)))->toBeNull()
        ->and(lock_await($a->renew('a', 5.0)))->toBeTrue()
        ->and(lock_await($b->renew('b', 5.0)))->toBeFalse();

    lock_await($b->release('b'));
    expect(lock_await($b->acquire('b', 5.0)))->toBeNull();

    lock_await($a->release('a'));
    expect(lock_await($b->acquire('b', 5.0)))->toBeInstanceOf(LeaseGrant::class);

    $a->close();
    $b->close();
})->with('backends');

it('frees the lock when the holder stops renewing', function (string $backend) {
    [$a, $b] = lock_pair($backend);

    lock_await($a->acquire('a', 0.4));
    expect(lock_await($b->acquire('b', 0.4)))->toBeNull();

    delay(0.8);

    expect(lock_await($b->acquire('b', 5.0)))->toBeInstanceOf(LeaseGrant::class)
        ->and(lock_await($a->renew('a', 5.0)))->toBeFalse();

    $a->close();
    $b->close();
})->with('backends');

it('reports how long the Redis lock was vacant', function () {
    [$a, $b] = lock_pair('redis');

    $first = lock_await($a->acquire('a', 5.0));
    lock_await($a->release('a'));
    delay(0.3);
    $second = lock_await($b->acquire('b', 5.0));

    expect($first->vacantSeconds)->toBeNull()
        ->and($second->vacantSeconds)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(1.0);

    $a->close();
    $b->close();
});

it('frees the PostgreSQL lock when the holding session ends', function () {
    [$a, $b] = lock_pair('pgsql');

    lock_await($a->acquire('a', 5.0));
    $a->close();

    expect(lock_await($b->acquire('b', 5.0)))->toBeInstanceOf(LeaseGrant::class);

    $b->close();
});
