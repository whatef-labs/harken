<?php

declare(strict_types=1);

/*
 * A long run (C-02: no memory growth over 24 hours).
 *
 *   php tests/E2E/soak.php [hours=24] [rate=1000]
 *
 * Two `harken:start` replicas (leader and standby, prometheus metrics on
 * ports 9180 and 9181) get device reports at <rate> per second on a leader
 * route, ten outbox commands and five PostgreSQL notifications per second,
 * a message to a failing handler every ten seconds, and run a leader
 * timer every second. Every minute the RSS (/proc) and PHP memory (/health) of
 * each replica go to build/soak-<run>/samples.csv; at the end the second
 * hour is compared with the last one.
 *
 * Needs docker/compose.yml (broker, Redis, PostgreSQL), ext-pgsql and
 * ext-posix. Env: HARKEN_TEST_BROKER_HOST (127.0.0.1).
 */

use React\EventLoop\Loop;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;

require __DIR__.'/../../vendor/autoload.php';

$hours = (float) ($argv[1] ?? 24);
$rate = (int) ($argv[2] ?? 1000);
$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$root = dirname(__DIR__, 2);
$run = date('Ymd-His');
$dir = "{$root}/build/soak-{$run}";
@mkdir($dir, recursive: true);
$csv = "{$dir}/samples.csv";
file_put_contents($csv, "elapsed_s,port,pid,rss_mb,php_mb,leader,received\n");
$prefix = 'soak_';

$env = [
    'HARKEN_HOST' => $host,
    'HARKEN_METRICS_DRIVER' => 'prometheus',
    'HARKEN_LEADER_LOCK' => 'redis',
    'HARKEN_LEADER_CLIENT_ID' => "harken-soak-{$run}-leader",
    'REDIS_PREFIX' => $prefix,
    // Its own cache, so harken:restart from another run cannot stop these replicas.
    'CACHE_STORE' => 'redis',
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => '127.0.0.1',
    'DB_DATABASE' => 'harken',
    'DB_USERNAME' => 'harken',
    'DB_PASSWORD' => 'harken',
    'E2E_MODE' => 'load',
    'E2E_SOAK' => '1',
] + getenv();

$replicas = [];

$start = static function (int $port) use (&$replicas, $env, $dir, $root): void {
    $replicas[$port] = proc_open(
        [PHP_BINARY, 'tests/E2E/artisan', 'harken:start'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/replica-{$port}.out", 'a'], 2 => ['file', "{$dir}/replica-{$port}.err", 'a']],
        $pipes,
        $root,
        ['HARKEN_HTTP_PORT' => (string) $port] + $env,
    );
};

$health = static function (int $port): ?array {
    $body = @file_get_contents("http://127.0.0.1:{$port}/health", context: stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
    $status = is_string($body) ? json_decode($body, true) : null;

    return is_array($status) ? $status : null;
};

$rss = static function (int $pid): ?float {
    preg_match('/^VmRSS:\s+(\d+) kB/m', (string) @file_get_contents("/proc/{$pid}/status"), $match);

    return isset($match[1]) ? round((int) $match[1] / 1024, 2) : null;
};

$start(9180);
delay(3.0);
$start(9181);
delay(2.0);

$seconds = (int) round($hours * 3600);
$generator = proc_open(
    [PHP_BINARY, 'tests/E2E/bench.php', 'generate', (string) $rate, (string) $seconds, '0'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/generator.out", 'w'], 2 => ['file', "{$dir}/generator.err", 'w']],
    $pipes,
    $root,
    $env,
);

$redis = new Redis;
$redis->connect('127.0.0.1');
$pg = pg_connect('host=127.0.0.1 dbname=harken user=harken password=harken');
$publisher = new Connection(
    new ConnectionOptions('soak', new Endpoint($host), "harken-soak-{$run}-pub", new ExponentialBackoff),
    new ReactSocketTransport(Loop::get()),
    ProtocolVersion::V5_0->codec(),
    Loop::get(),
);
await($publisher->connect());

$commands = 0;
$timers = [];
$timers[] = Loop::addPeriodicTimer(0.1, static function () use (&$commands, $redis, $prefix, $run): void {
    $fields = (new OutboxMessage("soak/{$run}/cmd/".($commands++ % 1000), '{"open":true}', 1))->toFields();
    $redis->rawCommand('XADD', $prefix.($commands % 10 === 0 ? 'harken:outbox:urgent' : 'harken:outbox:normal'), '*', ...array_merge(...array_map(null, array_keys($fields), array_values($fields))));
});
$timers[] = Loop::addPeriodicTimer(0.2, static fn () => pg_query($pg, "NOTIFY harken_soak, 'tick'"));
// Every failure is logged and reported with its trace: keep them rare.
$timers[] = Loop::addPeriodicTimer(10.0, static fn () => $publisher->publish('load/1/bad', '{}', 1)->then(null, static fn () => null));

$began = microtime(true);
$sample = static function () use (&$replicas, $began, $csv, $health, $rss): void {
    foreach ($replicas as $port => $process) {
        $pid = proc_get_status($process)['pid'];
        $status = $health($port);
        file_put_contents($csv, sprintf(
            "%d,%d,%d,%s,%s,%s,%s\n",
            (int) (microtime(true) - $began),
            $port,
            $pid,
            $rss($pid) ?? '',
            $status['memory_mb'] ?? '',
            ($status['leader']['state'] ?? '') === 'leading' ? '1' : '0',
            ($status['mqtt']['received'] ?? 0) + ($status['leader']['mqtt']['received'] ?? 0),
        ), FILE_APPEND);
    }
};
$sample();
$timers[] = Loop::addPeriodicTimer(60.0, $sample);

echo "Soak run {$run}: {$hours} h at {$rate} msg/s, samples in {$csv}\n";

while (proc_get_status($generator)['running']) {
    delay(5.0);
}

$sample();

// What the replicas did, from the counters of the leader at the end.
$totals = [];

foreach (array_keys($replicas) as $port) {
    foreach (explode("\n", (string) @file_get_contents("http://127.0.0.1:{$port}/metrics")) as $line) {
        // Route labels hold braces themselves ("load/{id}/up").
        if (preg_match('/^(harken_(?:notifications_handled|handlers_failed|outbox_published|messages_handled|reconnects|leader_takeovers)_total)(?:\{.*\})? (\S+)$/', $line, $m) === 1) {
            $totals[$m[1]] = ($totals[$m[1]] ?? 0) + (int) $m[2];
        }
    }
}

foreach ($timers as $timer) {
    Loop::cancelTimer($timer);
}

foreach ($replicas as $process) {
    posix_kill(proc_get_status($process)['pid'], SIGTERM);
}

foreach ($replicas as $process) {
    for ($i = 0; $i < 200 && proc_get_status($process)['running']; $i++) {
        usleep(50_000);
    }

    proc_close($process);
}

// ------------------------------------------------------------ analyse
$rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_slice(file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1));
$result = ['hours' => $hours, 'rate' => $rate, 'samples' => $csv, 'replicas' => []];
$pass = true;

foreach ([9180, 9181] as $port) {
    $own = array_values(array_filter($rows, static fn (array $row): bool => (int) $row[1] === $port && $row[3] !== ''));
    $last = (int) end($own)[0];
    // The second hour against the last one; shorter runs use quarters.
    $window = min(3600, max(60, intdiv($last, 4)));
    $mean = static function (int $from, int $to, int $column) use ($own): ?float {
        $values = array_map(static fn (array $row): float => (float) $row[$column], array_filter($own, static fn (array $row): bool => (int) $row[0] >= $from && (int) $row[0] < $to && $row[$column] !== ''));

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    };
    $early = [$mean($window, 2 * $window, 3), $mean($window, 2 * $window, 4)];
    $late = [$mean($last - $window, $last + 1, 3), $mean($last - $window, $last + 1, 4)];
    $growth = [
        'rss_mb' => $early[0] === null || $late[0] === null ? null : round($late[0] - $early[0], 2),
        'php_mb' => $early[1] === null || $late[1] === null ? null : round($late[1] - $early[1], 2),
    ];
    $pass = $pass && $growth['rss_mb'] !== null && $growth['rss_mb'] < 5.0 && $growth['php_mb'] !== null && $growth['php_mb'] < 2.0;
    $result['replicas'][$port] = [
        'rss_mb' => ['early' => $early[0], 'late' => $late[0], 'min' => min(array_column($own, 3)), 'max' => max(array_column($own, 3))],
        'php_mb' => ['early' => $early[1], 'late' => $late[1]],
        'growth' => $growth,
        'received' => (int) end($own)[6],
        'restarted' => count(array_unique(array_column($own, 2))) > 1,
    ];
}

$result['totals'] = $totals;
$result['generator'] = trim((string) file_get_contents("{$dir}/generator.out"));
file_put_contents("{$dir}/result.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
echo $pass ? "PASS\n" : "FAIL\n";

// End the leader session.
$cleanup = new Connection(
    new ConnectionOptions('soak', new Endpoint($host), $env['HARKEN_LEADER_CLIENT_ID'], new ExponentialBackoff),
    new ReactSocketTransport(Loop::get()),
    ProtocolVersion::V5_0->codec(),
    Loop::get(),
);
await($cleanup->connect());
await($cleanup->disconnect());
await($publisher->disconnect());
// react/async runs the loop to completion when the script ends: stop it.
Loop::stop();

exit($pass ? 0 : 1);
