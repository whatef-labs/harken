<?php

declare(strict_types=1);

use React\EventLoop\Loop;
use Whatef\Harken\Laravel\LeaderConfig;
use Whatef\Harken\Laravel\StoreConfig;
use Whatef\Harken\Runtime\Leadership\NullLeaderLock;
use Whatef\Harken\Runtime\Leadership\PgAdvisoryLeaderLock;
use Whatef\Harken\Runtime\Leadership\RedisLeaderLock;

it('derives the leader settings from the default connection', function () {
    $options = LeaderConfig::options(config('harken.connections.default'));

    expect($options->clientId)->toBe('laravel-harken-leader')
        ->and($options->sessionExpiry)->toBe(300)
        ->and($options->lock)->toBe('redis')
        ->and($options->lease)->toBe(5.0)
        ->and($options->retryInterval)->toBe(0.25)
        ->and($options->lockKey())->toBe('harken:leader:laravel-harken-leader');
});

it('keeps an explicit leader client id', function () {
    $options = LeaderConfig::options(['leader' => ['client_id' => 'plant-7-leader', 'lock' => 'none']]);

    expect($options->clientId)->toBe('plant-7-leader')
        ->and(LeaderConfig::lock($options, new StoreConfig(config()), Loop::get()))->toBeInstanceOf(NullLeaderLock::class);
});

it('rejects leader settings that cannot work', function (array $leader, string $message) {
    LeaderConfig::options(['leader' => $leader]);
})->with([
    [['lock' => 'zookeeper'], 'Unknown leader lock [zookeeper]'],
    [['lease' => 0.5], 'at least 1 second'],
    [['lease' => 2, 'retry_interval' => 2], 'shorter than the lease'],
    [['lease' => 10, 'session_expiry' => 5], 'at least the lease'],
])->throws(InvalidArgumentException::class);

it('builds a Redis lock on the application Redis connection, with its key prefix', function () {
    config()->set('database.redis.options.prefix', 'app_database_');
    $lock = LeaderConfig::lock(LeaderConfig::options(config('harken.connections.default')), new StoreConfig(config()), Loop::get());

    expect($lock)->toBeInstanceOf(RedisLeaderLock::class)
        ->and($lock->key)->toBe('app_database_harken:leader:laravel-harken-leader');

    $lock->close();
});

it('builds a PostgreSQL lock on a database connection', function () {
    if (! extension_loaded('pgsql')) {
        $this->markTestSkipped('ext-pgsql is not loaded.');
    }

    config()->set('database.connections.locks', ['driver' => 'pgsql', 'host' => 'db', 'database' => 'app', 'username' => 'u', 'password' => 'p']);
    $options = LeaderConfig::options(['leader' => ['lock' => 'pgsql', 'lock_connection' => 'locks']]);

    expect(LeaderConfig::lock($options, new StoreConfig(config()), Loop::get()))->toBeInstanceOf(PgAdvisoryLeaderLock::class);
});

it('reads Redis connections the way Laravel does', function () {
    config()->set('database.redis.options.prefix', 'global_');
    config()->set('database.redis.queue', ['url' => 'tls://user:s%40cret@cache.internal:6380/3']);
    config()->set('database.redis.local', ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1', 'options' => ['prefix' => 'local_']]);
    $stores = new StoreConfig(config());

    $queue = $stores->redis('queue');
    $local = $stores->redis('local');

    expect($queue->uri())->toBe('rediss://cache.internal:6380')
        ->and($queue->username)->toBe('user')
        ->and($queue->password)->toBe('s@cret')
        ->and($queue->database)->toBe(3)
        ->and($queue->prefix)->toBe('global_')
        ->and(print_r($queue, true))->not->toContain('s@cret')
        ->and($local->uri())->toBe('redis://127.0.0.1:6379')
        ->and($local->database)->toBe(1)
        ->and($local->prefix)->toBe('local_')
        ->and($local->hostIsIp())->toBeTrue();
});

it('builds a libpq connection string from a database connection', function () {
    config()->set('database.connections.main', [
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => '5433',
        'database' => 'app',
        'username' => 'harken',
        'password' => "it's\\secret",
        'sslmode' => 'require',
    ]);

    expect((new StoreConfig(config()))->postgres('main'))
        ->toBe("host='db.internal' port='5433' dbname='app' user='harken' password='it\\'s\\\\secret' sslmode='require' connect_timeout='5' application_name='harken'");
});

it('names missing or unsuitable store connections', function (callable $read, string $message) {
    expect(fn () => $read(new StoreConfig(config())))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [fn (StoreConfig $s) => $s->redis('nope'), 'Redis connection [nope] is not configured'],
    [fn (StoreConfig $s) => $s->postgres('nope'), 'Database connection [nope] is not configured'],
    [fn (StoreConfig $s) => $s->postgres('sqlite'), 'must use the pgsql driver'],
]);
