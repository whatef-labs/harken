<?php

declare(strict_types=1);

/*
 * Spike 04 — PostgreSQL LISTEN/NOTIFY as an event-loop source.
 *
 *   php spikes/04-pg-notify.php              run all experiments
 *   php spikes/04-pg-notify.php notify …     (internal) sender subprocess
 *
 * Env: SPIKE_PG_DSN (host=127.0.0.1 port=5432 dbname=postgres user=root)
 */

use React\EventLoop\Loop;

use function React\Async\await;
use function React\Promise\Timer\sleep as delay;

require __DIR__.'/support.php';

$dsn = getenv('SPIKE_PG_DSN') ?: 'host=127.0.0.1 port=5432 dbname=postgres user=root';
$channel = 'harken_spike';

// ---------------------------------------------------------------- sender
if (($argv[1] ?? null) === 'notify') {
    [, , $count, $rate] = $argv;
    $conn = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
    $start = microtime(true);
    for ($i = 0; $i < (int) $count; $i++) {
        $due = $start + $i / (float) $rate;
        while (($now = microtime(true)) < $due) {
            usleep(min(1000, (int) (($due - $now) * 1e6)));
        }
        pg_query_params($conn, 'SELECT pg_notify($1, $2)', [$channel, (string) (int) (microtime(true) * 1e6)]);
    }
    exit(0);
}

$sendInBackground = static function (int $count, int $rate) use ($argv) {
    return proc_open([PHP_BINARY, $argv[0], 'notify', (string) $count, (string) $rate], [], $pipes);
};

$cpu = static function (): float {
    $u = getrusage();

    return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6 + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
};

$loop = Loop::get();
spike_line('event loop', (new ReflectionClass($loop))->getShortName());
spike_line('platform', PHP_OS_FAMILY.' / PHP '.PHP_VERSION);

// ------------------------------------------------- E1 pg_socket on the loop
echo "\nE1  ext-pgsql: LISTEN + pg_socket() registered with addReadStream()\n";
$listener = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
spike_line('server version', pg_parameter_status($listener, 'server_version'));
pg_query($listener, "LISTEN {$channel}");
$socket = pg_socket($listener);
spike_line('pg_socket() type', get_debug_type($socket));

$latencies = [];
$received = 0;
$readable = 0;
$lost = 0;
$onReadable = static function () use (&$listener, &$socket, $loop, &$latencies, &$received, &$readable, &$lost): void {
    $readable++;
    if (! pg_consume_input($listener)) {
        // The socket is dead. It must leave the loop before the next select,
        // otherwise stream_select() throws and takes the whole loop down.
        $loop->removeReadStream($socket);
        $lost++;

        return;
    }
    while (($notify = pg_get_notify($listener, PGSQL_ASSOC)) !== false) {
        $received++;
        $sentAt = (int) explode(':', $notify['payload'])[0];
        $latencies[] = (microtime(true) * 1e6 - $sentAt) / 1000;
    }
};
$loop->addReadStream($socket, $onReadable);

$sender = $sendInBackground(300, 100);
$lag = spike_loop_lag($loop, 4.0);
proc_close($sender);
spike_line('received / sent', "{$received} / 300");
spike_line('NOTIFY -> callback latency (ms)', spike_percentiles($latencies));
spike_line('1 ms timer lag while listening (ms)', $lag);

// ------------------------------------------------- E2 burst
echo "\nE2  burst: 10000 NOTIFYs committed in one transaction (unique payloads)\n";
// PostgreSQL folds identical (channel, payload) pairs sent in one transaction
// into a single notification, so every payload carries a sequence number.
$latencies = [];
$received = 0;
$readable = 0;
$sender = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
$t = hrtime(true);
pg_query($sender, "SELECT pg_notify('{$channel}', (extract(epoch from clock_timestamp()) * 1000000)::bigint::text || ':' || g) FROM generate_series(1, 10000) g");
$sendMs = spike_ms($t);
$deadline = microtime(true) + 10;
while ($received < 10000 && microtime(true) < $deadline) {
    await(delay(0.01));
}
spike_line('sender query time (ms)', round($sendMs, 1));
spike_line('received', $received);
spike_line('readable callbacks', $readable);
spike_line('commit -> callback latency (ms)', spike_percentiles($latencies));

// ------------------------------------------------- E3 idle CPU and connection loss
echo "\nE3  idle cost and connection loss\n";
$c0 = $cpu();
await(delay(5.0));
spike_line('CPU seconds over 5 s idle (pg_socket)', round($cpu() - $c0, 3));

$pid = pg_get_pid($listener);
$readableBefore = $readable;
pg_query($sender, "SELECT pg_terminate_backend({$pid})");
await(delay(0.5));
spike_line('connection status after backend kill', pg_connection_status($listener) === PGSQL_CONNECTION_OK ? 'OK' : 'BAD');
spike_line('readable callbacks caused by the kill', $readable - $readableBefore);
spike_line('loss detected and stream removed', $lost === 1 ? 'yes' : 'no');

// Reconnect: new connection, LISTEN again, register the new socket.
$listener = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
pg_query($listener, "LISTEN {$channel}");
$socket = pg_socket($listener);
$loop->addReadStream($socket, $onReadable);
$received = 0;
pg_query($sender, "SELECT pg_notify('{$channel}', (extract(epoch from clock_timestamp()) * 1000000)::bigint::text || ':' || g) FROM generate_series(1, 100) g");
await(delay(0.5));
spike_line('received after reconnect + LISTEN', "{$received} / 100");
$loop->removeReadStream($socket);
pg_close($listener);

// ------------------------------------------------- E4 polling fallback (PDO)
echo "\nE4  fallback without ext-pgsql: Pdo\\Pgsql::getNotify() on a periodic timer\n";
$parts = [];
foreach (explode(' ', $dsn) as $pair) {
    [$k, $v] = explode('=', $pair, 2);
    $parts[$k] = $v;
}
$pdo = new Pdo\Pgsql("pgsql:host={$parts['host']};port={$parts['port']};dbname={$parts['dbname']}", $parts['user'], $parts['password'] ?? null);
$pdo->exec("LISTEN {$channel}");

foreach ([0.01, 0.05, 0.1] as $interval) {
    $latencies = [];
    $received = 0;
    $timer = $loop->addPeriodicTimer($interval, static function () use ($pdo, &$latencies, &$received) {
        while (($notify = $pdo->getNotify(PDO::FETCH_ASSOC, 0)) !== false) {
            $received++;
            $latencies[] = (microtime(true) * 1e6 - (int) $notify['payload']) / 1000;
        }
    });
    $c0 = $cpu();
    await(delay(5.0));
    $idleCpu = $cpu() - $c0;
    $sender = $sendInBackground(300, 100);
    await(delay(4.0));
    proc_close($sender);
    $loop->cancelTimer($timer);

    $ms = (int) ($interval * 1000);
    spike_line("poll every {$ms} ms: CPU seconds over 5 s idle", round($idleCpu, 3));
    spike_line("poll every {$ms} ms: received / sent", "{$received} / 300");
    spike_line("poll every {$ms} ms: latency (ms)", spike_percentiles($latencies));
}

$loop->stop();
