<?php

declare(strict_types=1);

/*
 * Leader takeover, end to end (requirement T4).
 *
 *   php tests/E2E/takeover.php kill|term [rate=100] [seconds=12]
 *
 * Starts two `harken:start` replicas with a leader route, publishes QoS 1
 * messages at a steady rate, stops the leader (SIGKILL or SIGTERM) a few
 * seconds in, and checks that:
 *   - no message is lost,
 *   - the two replicas never received at the same time,
 *   - the leader timer ran on one replica at a time,
 *   - the standby took over within the lease (SIGKILL) or within a second
 *     (SIGTERM, which releases the lock at once).
 *
 * Needs the broker and Redis from docker/compose.yml, ext-pcntl and
 * ext-posix. Env: HARKEN_TEST_BROKER_HOST (127.0.0.1).
 */

use React\EventLoop\Loop;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\all;

require __DIR__.'/../../vendor/autoload.php';

$mode = $argv[1] ?? 'kill';
$rate = (int) ($argv[2] ?? 100);
$seconds = (float) ($argv[3] ?? 12);
$lease = 5.0;
$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$run = bin2hex(random_bytes(4));
$topic = "harken-e2e/{$run}/up";
$dir = sys_get_temp_dir()."/harken-e2e-{$run}";
mkdir($dir);
$log = "{$dir}/received.log";
touch($log);

$env = [
    'HARKEN_HOST' => $host,
    'HARKEN_LEADER_LOCK' => 'redis',
    'HARKEN_LEADER_CLIENT_ID' => "harken-e2e-{$run}-leader",
    'HARKEN_LEADER_LEASE' => (string) $lease,
    'HARKEN_OUTBOX' => '0',
    'E2E_TOPIC' => $topic,
    'E2E_LOG' => $log,
] + getenv();

$spawn = static function (string $name) use ($env, $dir): array {
    $process = proc_open(
        [PHP_BINARY, 'tests/E2E/artisan', 'harken:start'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/{$name}.out", 'w'], 2 => ['file', "{$dir}/{$name}.err", 'w']],
        $pipes,
        dirname(__DIR__, 2),
        $env,
    );

    return [$process, proc_get_status($process)['pid']];
};

$waitFor = static function (string $file, string $needle, float $timeout): bool {
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        if (str_contains((string) @file_get_contents($file), $needle)) {
            return true;
        }

        delay(0.05);
    }

    return false;
};

// Replica A starts first and leads; B stands by.
[$a, $pidA] = $spawn('a');

if (! $waitFor("{$dir}/a.out", 'Leading', 15)) {
    fwrite(STDERR, "Replica A did not become leader.\n".file_get_contents("{$dir}/a.out").file_get_contents("{$dir}/a.err"));
    exit(1);
}

[$b, $pidB] = $spawn('b');
delay(2.0);

$publisher = new Connection(
    new ConnectionOptions('e2e', new Endpoint($host), "harken-e2e-{$run}-pub", new ExponentialBackoff),
    new ReactSocketTransport(Loop::get()),
    ProtocolVersion::V5_0->codec(),
    Loop::get(),
);
await($publisher->connect());

$total = (int) ($rate * $seconds);
$sent = 0;
$acks = [];
$stoppedAt = null;
$killAfter = 3.0;
$start = microtime(true);
$timer = Loop::addPeriodicTimer(1 / $rate, static function () use (&$sent, &$acks, $total, $publisher, $topic, &$timer, &$stoppedAt, $start, $killAfter, $pidA, $mode): void {
    if ($stoppedAt === null && microtime(true) - $start >= $killAfter) {
        $stoppedAt = microtime(true);
        posix_kill($pidA, $mode === 'term' ? SIGTERM : SIGKILL);
    }

    if ($sent >= $total) {
        Loop::cancelTimer($timer);

        return;
    }

    $acks[] = $publisher->publish($topic, (string) $sent++, 1);
});

while ($sent < $total) {
    delay(0.1);
}

await(all($acks));
delay($lease + 3.0);

posix_kill($pidB, SIGTERM);
$exitB = null;

for ($i = 0; $i < 200 && ($status = proc_get_status($b))['running']; $i++) {
    delay(0.05);
}

proc_close($a);
proc_close($b);
$publisher->disconnect();

// Analyse: "pid seq time" per message, "pid timer time" per timer run.
$received = [];
$byPid = [];
$timers = [];

foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$pid, $seq, $time] = explode(' ', $line);

    if ($seq === 'timer') {
        $timers[(int) $pid][] = (float) $time;

        continue;
    }

    $received[(int) $seq][] = (int) $pid;
    $byPid[(int) $pid][] = (float) $time;
}

$timerOverlap = isset($timers[$pidA], $timers[$pidB]) && min($timers[$pidB]) < max($timers[$pidA]);

$lost = array_values(array_diff(range(0, $total - 1), array_keys($received)));
$duplicates = count(array_filter($received, static fn (array $pids): bool => count($pids) > 1));
$lastA = isset($byPid[$pidA]) ? max($byPid[$pidA]) : null;
$firstB = isset($byPid[$pidB]) ? min($byPid[$pidB]) : null;
$overlap = $lastA !== null && $firstB !== null && $firstB < $lastA;
$takeover = $firstB === null ? null : $firstB - $stoppedAt;

$result = [
    'mode' => $mode,
    'rate' => $rate,
    'sent' => $total,
    'received_unique' => count($received),
    'lost' => count($lost),
    'duplicates' => $duplicates,
    'received_by_a' => count($byPid[$pidA] ?? []),
    'received_by_b' => count($byPid[$pidB] ?? []),
    'overlap' => $overlap,
    'timer_runs_a' => count($timers[$pidA] ?? []),
    'timer_runs_b' => count($timers[$pidB] ?? []),
    'timer_overlap' => $timerOverlap,
    'gap_seconds' => $lastA === null || $firstB === null ? null : round($firstB - $lastA, 3),
    'takeover_after_stop_seconds' => $takeover === null ? null : round($takeover, 3),
    'b_exit_status' => $status['exitcode'] ?? null,
    'logs' => $dir,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$limit = $mode === 'term' ? 1.0 : $lease + 1.0;
$pass = $lost === [] && ! $overlap && ! $timerOverlap && isset($timers[$pidA], $timers[$pidB])
    && $takeover !== null && $takeover <= $limit;

echo $pass ? "PASS\n" : "FAIL\n";
exit($pass ? 0 : 1);
