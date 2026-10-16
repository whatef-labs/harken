<?php

declare(strict_types=1);

/*
 * PostgreSQL LISTEN/NOTIFY against a real server, with ext-pgsql and with
 * the PDO polling fallback.
 *
 *   HARKEN_TEST_PG_DSN="host=127.0.0.1 port=5432 dbname=harken user=harken password=harken" \
 *   vendor/bin/pest --group=integration
 */

use React\EventLoop\Loop;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Runtime\Sources\Notification;
use Whatef\Harken\Runtime\Sources\PgNotifySource;

use function React\Async\delay;

beforeEach(function () {
    if (! getenv('HARKEN_TEST_PG_DSN') || ! extension_loaded('pgsql')) {
        $this->markTestSkipped('Set HARKEN_TEST_PG_DSN (and load ext-pgsql to send notifications) to run these tests.');
    }
});

afterEach(function () {
    foreach (pg_sources() as $source) {
        $source->stop();
    }

    pg_sources(reset: true);
});

/**
 * @return list<PgNotifySource>
 */
function pg_sources(?PgNotifySource $source = null, bool $reset = false): array
{
    static $sources = [];

    if ($reset) {
        $sources = [];
    }

    if ($source !== null) {
        $sources[] = $source;
    }

    return $sources;
}

/**
 * @param  list<Notification>  $received
 */
function pg_source(bool $extension, array &$received, ?callable $guard = null, array &$errors = []): PgNotifySource
{
    $channel = 'harken_it_'.bin2hex(random_bytes(4));
    $source = new PgNotifySource(
        (string) getenv('HARKEN_TEST_PG_DSN'),
        [$channel],
        function (Notification $n) use (&$received): void {
            $received[] = $n;
        },
        pollIntervalMs: 10,
        backoff: new ExponentialBackoff(initial: 0.1, max: 0.5, jitter: 0.0),
        onError: function (Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        },
        guard: $guard,
        useExtension: $extension,
    );

    return pg_sources($source)[array_key_last(pg_sources())];
}

function pg_notify_now(string $channel, string $payload): void
{
    $sender = pg_connect((string) getenv('HARKEN_TEST_PG_DSN'), PGSQL_CONNECT_FORCE_NEW);
    pg_query_params($sender, 'SELECT pg_notify($1, $2)', [$channel, $payload]);
    pg_close($sender);
}

function pg_wait(callable $condition, float $seconds = 5.0): void
{
    $deadline = microtime(true) + $seconds;

    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for notifications.');
        }

        delay(0.01);
    }
}

dataset('drivers', ['ext-pgsql' => [true], 'pdo' => [false]]);

it('resyncs on start, then delivers notifications with their payload', function (bool $extension) {
    $received = [];
    $source = pg_source($extension, $received);
    $channel = $source->channels[0];

    $source->start(Loop::get());
    pg_wait(function () use (&$received) {
        return count($received) === 1;
    });
    pg_notify_now($channel, 'order:42');
    pg_notify_now($channel, '');
    pg_wait(function () use (&$received) {
        return count($received) === 3;
    });

    expect($received)->toEqual([
        new Notification($channel, '', true),
        new Notification($channel, 'order:42'),
        new Notification($channel, ''),
    ])->and($source->isListening())->toBeTrue();
})->with('drivers');

it('reconnects, listens again and resyncs after losing its session', function (bool $extension) {
    $received = [];
    $errors = [];
    $source = pg_source($extension, $received, null, $errors);
    $channel = $source->channels[0];
    $source->start(Loop::get());
    pg_wait(function () use (&$received) {
        return count($received) === 1;
    });

    // End the listening session from another connection.
    $admin = pg_connect((string) getenv('HARKEN_TEST_PG_DSN'), PGSQL_CONNECT_FORCE_NEW);
    pg_query_params($admin, 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND query = $1', ['LISTEN "'.$channel.'"']);
    pg_close($admin);

    if (! $extension) {
        // PDO notices the loss on its next query; run the health check now.
        (fn () => ($this->healthTimer?->getCallback())())->call($source);
    }

    pg_wait(function () use (&$received) {
        return count($received) === 2;
    });
    pg_notify_now($channel, 'after');
    pg_wait(function () use (&$received) {
        return count($received) === 3;
    });

    expect($received[1])->toEqual(new Notification($channel, '', true))
        ->and($received[2])->toEqual(new Notification($channel, 'after'))
        ->and($errors)->not->toBe([]);
})->with('drivers');

it('drops notifications while its guard says no', function () {
    $received = [];
    $allowed = true;
    $source = pg_source(true, $received, function () use (&$allowed) {
        return $allowed;
    });
    $channel = $source->channels[0];
    $source->start(Loop::get());
    pg_wait(function () use (&$received) {
        return count($received) === 1;
    });

    $allowed = false;
    pg_notify_now($channel, 'ignored');
    delay(0.3);
    $allowed = true;
    pg_notify_now($channel, 'kept');
    pg_wait(function () use (&$received) {
        return count($received) === 2;
    });

    expect($received[1]->payload)->toBe('kept');
});
