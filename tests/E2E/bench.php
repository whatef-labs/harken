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
 *       latency (publish -> handler, sampled), CPU per message and memory.
 *
 *   php tests/E2E/bench.php outbox <count>
 *       Queues <count> outbox entries at once and measures how fast the
 *       runtime publishes them (QoS 1, each acknowledged by the broker).
 *
 * Needs the stack in docker/compose.yml. The broker, Redis, the generators
 * and the runtime share the machine. Env switches for inbound runs:
 *
 *   E2E_REPLICAS=<n>         n runtime processes sharing the route ($share)
 *   E2E_WORKERS=<n>          the route is ->queued(), n queue:work processes run it
 *   E2E_SINGLETON=1          the handler is bound as a singleton
 *   E2E_QOS=0                publish and subscribe at QoS 0
 *   E2E_PHP_ARGS="-d ..."    php options for the runtime processes (JIT, ext-uv)
 *   E2E_RUNTIME_CPUS=5,4     pin runtime i to the i-th CPU of the list (taskset)
 *   E2E_OTHER_CPUS=0-3       pin generators and workers to these CPUs
 *   HARKEN_METRICS_DRIVER    as for any runtime
 *
 * With the broker's process visible (docker run --pid host) its CPU is
 * reported as well.
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
    $qos = getenv('E2E_QOS') === '0' ? 0 : 1;
    $publisher = $connect('harken-bench-gen-'.bin2hex(random_bytes(3)));
    $total = (int) ($rate * (float) $seconds);
    $sent = 0;
    $start = microtime(true);
    $done = false;

    Loop::addPeriodicTimer(0.005, static function ($timer) use (&$sent, &$done, $total, $rate, $start, $publisher, $offset, $qos): void {
        $due = min($total, (int) ((microtime(true) - $start) * $rate));

        while ($sent < $due) {
            $seq = (int) $offset + $sent;
            $publisher->publish('load/'.($seq % 1000).'/up', DeviceReport::payload($seq, microtime(true)), $qos);
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
    $usage = getrusage();
    echo json_encode([
        'sent' => $sent,
        'seconds' => round(microtime(true) - $start, 3),
        'cpu_seconds' => $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6,
    ]), "\n";
    $publisher->disconnect();
    exit(0);
}

// ------------------------------------------------------------ helpers
$run = bin2hex(random_bytes(4));
$dir = sys_get_temp_dir()."/harken-bench-{$run}";
mkdir($dir);
$prefix = 'bench_';
$replicas = $mode === 'inbound' ? max(1, (int) getenv('E2E_REPLICAS')) : 1;
$workers = $mode === 'inbound' ? max(0, (int) getenv('E2E_WORKERS')) : 0;
$runtimeCpus = array_values(array_filter(explode(',', (string) getenv('E2E_RUNTIME_CPUS')), static fn (string $cpu): bool => $cpu !== ''));
$otherCpus = (string) getenv('E2E_OTHER_CPUS');
$phpArgs = preg_split('/\s+/', trim((string) getenv('E2E_PHP_ARGS')), flags: PREG_SPLIT_NO_EMPTY) ?: [];

$env = [
    'HARKEN_HOST' => $host,
    // Several replicas elect one leader for the leader routes the load app also has.
    'HARKEN_LEADER_LOCK' => $replicas > 1 ? 'redis' : 'none',
    'HARKEN_LEADER_CLIENT_ID' => "harken-bench-{$run}-leader",
    'HARKEN_LOG_CHANNEL' => 'null',
    'HARKEN_OUTBOX' => $mode === 'outbox' ? '1' : '0',
    'REDIS_PREFIX' => $prefix,
    'QUEUE_CONNECTION' => 'redis',
    'E2E_MODE' => 'load',
    'E2E_DISTRIBUTION' => $replicas > 1 ? 'shared' : (getenv('E2E_DISTRIBUTION') ?: 'leader'),
    'E2E_QUEUED' => $workers > 0 ? '1' : '',
    'E2E_LATENCY_LOG' => "{$dir}/latency.log",
] + getenv();

$spawn = static function (array $command, string $name, string $cpus = '', array $php = []) use ($env, $dir, $root): array {
    $process = proc_open(
        [...($cpus === '' ? [] : ['taskset', '-c', $cpus]), PHP_BINARY, ...$php, ...$command],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/{$name}.out", 'w'], 2 => ['file', "{$dir}/{$name}.err", 'w']],
        $pipes,
        $root,
        $env,
    );

    return [$process, proc_get_status($process)['pid']];
};

$cpu = static function (int $pid, bool $kernel = false): float {
    $stat = explode(' ', (string) @file_get_contents("/proc/{$pid}/stat"));

    return ($kernel ? 0 : (int) ($stat[13] ?? 0)) / 100 + (int) ($stat[14] ?? 0) / 100;
};

$rss = static function (int $pid): float {
    preg_match('/VmRSS:\s+(\d+) kB/', (string) @file_get_contents("/proc/{$pid}/status"), $m);

    return isset($m[1]) ? round((int) $m[1] / 1024, 1) : 0.0;
};

// CPU seconds the whole machine spent busy (/proc/stat is not per container).
$machine = static function (): float {
    $ticks = array_map('intval', array_slice(preg_split('/\s+/', (string) strtok((string) file_get_contents('/proc/stat'), "\n")) ?: [], 1, 8));

    return (array_sum($ticks) - ($ticks[3] ?? 0) - ($ticks[4] ?? 0)) / 100;
};

// The broker's process, visible when this runs in the host's process namespace.
$brokerPid = null;

foreach (glob('/proc/[0-9]*/comm') ?: [] as $comm) {
    if (trim((string) @file_get_contents($comm)) === 'beam.smp') {
        $brokerPid = (int) basename(dirname($comm));
        break;
    }
}

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

$redis = new Redis;
$redis->connect('127.0.0.1');
$queue = "{$prefix}queues:default";
$redis->del($queue, "{$queue}:reserved", "{$queue}:delayed", "{$queue}:notify");
$runtimes = [];

for ($i = 0; $i < $replicas; $i++) {
    $runtimes[] = $spawn(['tests/E2E/artisan', 'harken:start'], "runtime{$i}", $runtimeCpus === [] ? '' : $runtimeCpus[$i % count($runtimeCpus)], $phpArgs);
}

$pid = $runtimes[0][1];
$pids = array_column($runtimes, 1);

foreach (array_keys($runtimes) as $i) {
    // A leader route is subscribed once the replica leads, a shared one on connect.
    if (! $waitFor("{$dir}/runtime{$i}.out", $env['E2E_DISTRIBUTION'] === 'shared' ? 'Starting Harken' : 'Leading', 20)) {
        fwrite(STDERR, "The runtime did not start.\n".file_get_contents("{$dir}/runtime{$i}.out").file_get_contents("{$dir}/runtime{$i}.err"));
        exit(1);
    }
}

$workerProcesses = [];

for ($i = 0; $i < $workers; $i++) {
    $workerProcesses[] = $spawn(['tests/E2E/artisan', 'queue:work', 'redis', '--sleep=0.01'], "worker{$i}", $otherCpus);
}

delay(1.0);
$result = ['mode' => $mode];

// ------------------------------------------------------------ inbound
if ($mode === 'inbound') {
    $rate = (int) ($argv[2] ?? 10000);
    $seconds = (float) ($argv[3] ?? 10);
    $count = max(1, (int) ($argv[4] ?? 2));
    $droppedBefore = $brokerDropped();
    $cpuStart = array_map($cpu, $pids);
    $kernel = static fn (): float => array_sum(array_map(static fn (int $pid): float => $cpu($pid, true), $pids));
    $kernelStart = $kernel();
    $brokerStart = $brokerPid === null ? 0.0 : $cpu($brokerPid);
    $machineStart = $machine();
    $wallStart = microtime(true);
    $generators = [];

    for ($i = 0; $i < $count; $i++) {
        $generators[] = $spawn(['tests/E2E/bench.php', 'generate', (string) ($rate / $count), (string) $seconds, (string) ($i * 100_000_000)], "generator{$i}", $otherCpus)[0];
    }

    $peakRss = 0.0;

    while (array_filter($generators, static fn ($g) => proc_get_status($g)['running']) !== []) {
        $peakRss = max($peakRss, ...array_map($rss, $pids));
        delay(0.2);
    }

    $cpuUsed = array_map(static fn (int $pid, float $start): float => $cpu($pid) - $start, $pids, $cpuStart);
    $wall = microtime(true) - $wallStart;
    $brokerUsed = $brokerPid === null ? null : $cpu($brokerPid) - $brokerStart;
    $machineUsed = $machine() - $machineStart;
    $drained = null;

    if ($workers > 0) {
        // Queued handlers: until the workers have run every job.
        while (($redis->lLen($queue) > 0 || $redis->zCard("{$queue}:reserved") > 0) && microtime(true) - $wallStart < $seconds + 300) {
            delay(0.1);
        }

        $drained = round(microtime(true) - $wallStart - $wall, 2);
    }

    delay(6.5); // past the next status heartbeat, so its counters include the backlog

    $sent = 0;
    $elapsed = 0.0;
    $generatorCpu = 0.0;

    for ($i = 0; $i < $count; $i++) {
        $out = json_decode((string) file_get_contents("{$dir}/generator{$i}.out"), true) ?: ['sent' => 0, 'seconds' => 0, 'cpu_seconds' => 0];
        $sent += $out['sent'];
        $elapsed = max($elapsed, $out['seconds']);
        $generatorCpu += $out['cpu_seconds'];
    }

    [$status] = $spawn(['tests/E2E/artisan', 'harken:status', '--json'], 'status');

    while (proc_get_status($status)['running']) {
        delay(0.05);
    }

    $json = (string) file_get_contents("{$dir}/status.out");
    $statuses = json_decode(substr($json, 0, (int) strrpos($json, ']') + 1), true) ?: [];
    $perReplica = [];

    foreach ($pids as $runtimePid) {
        $mine = array_values(array_filter($statuses, static fn (array $s): bool => ($s['pid'] ?? null) === $runtimePid))[0] ?? [];
        $perReplica[] = (int) ($mine['mqtt']['received'] ?? 0) + (int) ($mine['leader']['mqtt']['received'] ?? 0);
    }

    $handled = array_sum($perReplica);
    $cpuTotal = array_sum(array_map(static fn (int $pid, float $start): float => $cpu($pid) - $start, $pids, $cpuStart));
    $workerCpu = array_sum(array_map(static fn (array $worker): float => $cpu($worker[1]), $workerProcesses));
    $latency = array_map('floatval', file("{$dir}/latency.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

    $result += [
        'rate' => $rate,
        'achieved_rate' => $elapsed > 0 ? (int) round($sent / $elapsed) : 0,
        'sent' => $sent,
        'handled' => $handled,
        'lost' => max(0, $sent - $handled),
        'broker_dropped' => $brokerDropped() - $droppedBefore,
        'latency_ms' => ['p50' => $percentile($latency, 0.5), 'p99' => $percentile($latency, 0.99), 'max' => $percentile($latency, 1.0), 'samples' => count($latency)],
        // While the generators ran; per message until the backlog was handled.
        'cpu_percent' => round(array_sum($cpuUsed) / $wall * 100, 1),
        'cpu_us_per_message' => $handled > 0 ? round($cpuTotal / $handled * 1e6, 1) : null,
        'cpu_kernel_share' => $cpuTotal > 0 ? round(($kernel() - $kernelStart) / $cpuTotal, 2) : null,
        'rss_mb' => $peakRss,
        'generator_cpu_percent' => round($generatorCpu / $wall * 100, 1),
        'machine_busy_cores' => round($machineUsed / $wall, 2),
    ];

    if ($brokerUsed !== null) {
        $result['broker_cpu_percent'] = round($brokerUsed / $wall * 100, 1);
    }

    if ($replicas > 1) {
        $result += [
            'replicas' => $replicas,
            'handled_per_replica' => $perReplica,
            'cpu_percent_per_replica' => array_map(static fn (float $used): float => round($used / $wall * 100, 1), $cpuUsed),
        ];
    }

    if ($workers > 0) {
        $result += [
            'workers' => $workers,
            'queue_drained_after_seconds' => $drained,
            'worker_cpu_us_per_message' => $handled > 0 ? round($workerCpu / $handled * 1e6, 1) : null,
        ];
    }
}

// ------------------------------------------------------------ outbox
if ($mode === 'outbox') {
    $count = (int) ($argv[2] ?? 50000);
    $stream = "{$prefix}harken:outbox:normal";
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

foreach ($pids as $runtimePid) {
    posix_kill($runtimePid, SIGTERM);
}

foreach ($workerProcesses as [$worker]) {
    proc_terminate($worker);
}

for ($i = 0; $i < 100 && array_filter($runtimes, static fn (array $r): bool => proc_get_status($r[0])['running']) !== []; $i++) {
    delay(0.1);
}

// End the leader session, so the broker stops queuing load messages for it.
$cleanup = $connect($env['HARKEN_LEADER_CLIENT_ID']);
await($cleanup->disconnect());

echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
