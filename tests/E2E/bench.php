<?php

declare(strict_types=1);

/*
 * Throughput and latency of one runtime process, for docs/benchmarks.md
 * and the README.
 *
 *   php tests/E2E/bench.php inbound <rate> [seconds=10] [generators=2]
 *       QoS 1 device reports (~400 bytes, JSON + HMAC check + in-memory
 *       state per message) over 1000 topics at <rate> messages per second,
 *       sent by <generators> processes. Reports messages lost, end-to-end
 *       latency (publish -> handler, sampled), CPU and memory.
 *
 *   php tests/E2E/bench.php outbox <count>
 *       Queues <count> outbox entries at once and measures how fast the
 *       runtime publishes them (QoS 1, each acknowledged by the broker).
 *
 * Needs the stack in docker/compose.yml. The broker, Redis, the generators
 * and the runtime share the machine.
 */

use React\EventLoop\Loop;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Tests\E2E\Load\DeviceReport;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;

require __DIR__.'/../../vendor/autoload.php';

$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? 'inbound';

$connect = static function (string $id) use ($host): Connection {
    $connection = new Connection(
        new ConnectionOptions('bench', new Endpoint($host), $id, new ExponentialBackoff, keepAlive: 60),
        new ReactSocketTransport(Loop::get()),
        ProtocolVersion::V5_0->codec(),
        Loop::get(),
    );
    await($connection->connect());

    return $connection;
};

// ------------------------------------------------------------ generator
if ($mode === 'generate') {
    [, , $rate, $seconds, $offset] = $argv;
    $rate = (float) $rate;
    $publisher = $connect('harken-bench-gen-'.bin2hex(random_bytes(3)));
    $total = (int) ($rate * (float) $seconds);
    $sent = 0;
    $start = microtime(true);
    $done = false;

    Loop::addPeriodicTimer(0.005, static function ($timer) use (&$sent, &$done, $total, $rate, $start, $publisher, $offset): void {
        $due = min($total, (int) ((microtime(true) - $start) * $rate));

        while ($sent < $due) {
            $seq = (int) $offset + $sent;
            $publisher->publish('load/'.($seq % 1000).'/up', DeviceReport::payload($seq, microtime(true)), 1);
            $sent++;
        }

        if ($sent >= $total) {
            Loop::cancelTimer($timer);
            $done = true;
        }
    });

    while (! $done) {
        delay(0.05);
    }

    await($publisher->whenIdle());
    echo json_encode(['sent' => $sent, 'seconds' => round(microtime(true) - $start, 3)]), "\n";
    $publisher->disconnect();
    exit(0);
}

// ------------------------------------------------------------ helpers
$run = bin2hex(random_bytes(4));
$dir = sys_get_temp_dir()."/harken-bench-{$run}";
mkdir($dir);
$prefix = 'bench_';

$env = [
    'HARKEN_HOST' => $host,
    'HARKEN_LEADER_LOCK' => 'none',
    'HARKEN_LEADER_CLIENT_ID' => "harken-bench-{$run}-leader",
    'HARKEN_LOG_CHANNEL' => 'null',
    'HARKEN_OUTBOX' => $mode === 'outbox' ? '1' : '0',
    'REDIS_PREFIX' => $prefix,
    'E2E_MODE' => 'load',
    'E2E_LATENCY_LOG' => "{$dir}/latency.log",
] + getenv();

$spawn = static function (array $command, string $name) use ($env, $dir, $root): array {
    $process = proc_open(
        [PHP_BINARY, ...$command],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/{$name}.out", 'w'], 2 => ['file', "{$dir}/{$name}.err", 'w']],
        $pipes,
        $root,
        $env,
    );

    return [$process, proc_get_status($process)['pid']];
};

$cpu = static function (int $pid): float {
    $stat = explode(' ', (string) @file_get_contents("/proc/{$pid}/stat"));

    return ((int) ($stat[13] ?? 0) + (int) ($stat[14] ?? 0)) / 100;
};

$rss = static function (int $pid): float {
    preg_match('/VmRSS:\s+(\d+) kB/', (string) @file_get_contents("/proc/{$pid}/status"), $m);

    return isset($m[1]) ? round((int) $m[1] / 1024, 1) : 0.0;
};

$brokerDropped = static function (): int {
    $text = (string) @file_get_contents('http://127.0.0.1:18083/api/v5/prometheus/stats');

    return preg_match('/^emqx_delivery_dropped_queue_full (\d+)/m', $text, $m) === 1 ? (int) $m[1] : 0;
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

$percentile = static function (array $samples, float $p): float {
    if ($samples === []) {
        return 0.0;
    }

    sort($samples);

    return round($samples[(int) min(count($samples) - 1, floor($p * count($samples)))], 2);
};

[$runtime, $pid] = $spawn(['tests/E2E/artisan', 'harken:start'], 'runtime');

if (! $waitFor("{$dir}/runtime.out", 'Leading', 20)) {
    fwrite(STDERR, "The runtime did not start.\n".file_get_contents("{$dir}/runtime.out").file_get_contents("{$dir}/runtime.err"));
    exit(1);
}

delay(1.0);
$result = ['mode' => $mode];

// ------------------------------------------------------------ inbound
if ($mode === 'inbound') {
    $rate = (int) ($argv[2] ?? 10000);
    $seconds = (float) ($argv[3] ?? 10);
    $count = max(1, (int) ($argv[4] ?? 2));
    $droppedBefore = $brokerDropped();
    $cpuStart = $cpu($pid);
    $wallStart = microtime(true);
    $generators = [];

    for ($i = 0; $i < $count; $i++) {
        $generators[] = $spawn(['tests/E2E/bench.php', 'generate', (string) ($rate / $count), (string) $seconds, (string) ($i * 100_000_000)], "generator{$i}")[0];
    }

    $peakRss = 0.0;

    while (array_filter($generators, static fn ($g) => proc_get_status($g)['running']) !== []) {
        $peakRss = max($peakRss, $rss($pid));
        delay(0.2);
    }

    $cpuUsed = $cpu($pid) - $cpuStart;
    $wall = microtime(true) - $wallStart;
    delay(6.5); // past the next status heartbeat, so its counters include the backlog

    $sent = 0;
    $elapsed = 0.0;

    for ($i = 0; $i < $count; $i++) {
        $out = json_decode((string) file_get_contents("{$dir}/generator{$i}.out"), true) ?: ['sent' => 0, 'seconds' => 0];
        $sent += $out['sent'];
        $elapsed = max($elapsed, $out['seconds']);
    }

    [$status] = $spawn(['tests/E2E/artisan', 'harken:status', '--json'], 'status');

    while (proc_get_status($status)['running']) {
        delay(0.05);
    }

    $json = (string) file_get_contents("{$dir}/status.out");
    $statuses = json_decode(substr($json, 0, (int) strrpos($json, ']') + 1), true) ?: [];
    $mine = array_values(array_filter($statuses, static fn (array $s): bool => ($s['pid'] ?? null) === $pid))[0] ?? [];
    $handled = (int) ($mine['leader']['mqtt']['received'] ?? 0);
    $latency = array_map('floatval', file("{$dir}/latency.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

    $result += [
        'rate' => $rate,
        'achieved_rate' => $elapsed > 0 ? (int) round($sent / $elapsed) : 0,
        'sent' => $sent,
        'handled' => $handled,
        'lost' => max(0, $sent - $handled),
        'broker_dropped' => $brokerDropped() - $droppedBefore,
        'latency_ms' => ['p50' => $percentile($latency, 0.5), 'p99' => $percentile($latency, 0.99), 'max' => $percentile($latency, 1.0), 'samples' => count($latency)],
        'cpu_percent' => round($cpuUsed / $wall * 100, 1),
        'rss_mb' => $peakRss,
    ];
}

// ------------------------------------------------------------ outbox
if ($mode === 'outbox') {
    $count = (int) ($argv[2] ?? 50000);
    $stream = "{$prefix}harken:outbox:normal";
    $redis = new Redis;
    $redis->connect('127.0.0.1');
    $fields = (new OutboxMessage('bench/out/1', str_repeat('x', 200), 1))->toFields();
    $cpuStart = $cpu($pid);
    $start = microtime(true);
    $pipe = $redis->multi(Redis::PIPELINE);

    for ($i = 0; $i < $count; $i++) {
        $pipe->rawCommand('XADD', $stream, '*', 'topic', "bench/out/{$i}", 'payload', $fields['payload'], 'qos', '1', 'retain', '0', 'enqueued_at', (string) (int) (microtime(true) * 1000));

        if ($i % 1000 === 999) {
            $pipe->exec();
            $pipe = $redis->multi(Redis::PIPELINE);
        }
    }

    $pipe->exec();
    $queued = microtime(true) - $start;
    $peakRss = 0.0;

    while ((int) $redis->rawCommand('XLEN', $stream) > 0 && microtime(true) - $start < 300) {
        $peakRss = max($peakRss, $rss($pid));
        delay(0.05);
    }

    $seconds = microtime(true) - $start;
    $left = (int) $redis->rawCommand('XLEN', $stream);
    $result += [
        'entries' => $count,
        'queued_in_seconds' => round($queued, 2),
        'drained_in_seconds' => round($seconds, 2),
        'published_per_second' => (int) round(($count - $left) / $seconds),
        'left' => $left,
        'cpu_percent' => round(($cpu($pid) - $cpuStart) / $seconds * 100, 1),
        'rss_mb' => $peakRss,
    ];
    $redis->del($stream, "{$prefix}harken:outbox:urgent");
}

posix_kill($pid, SIGTERM);

for ($i = 0; $i < 100 && proc_get_status($runtime)['running']; $i++) {
    delay(0.1);
}

// End the leader session, so the broker stops queuing load messages for it.
$cleanup = $connect($env['HARKEN_LEADER_CLIENT_ID']);
await($cleanup->disconnect());

echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
