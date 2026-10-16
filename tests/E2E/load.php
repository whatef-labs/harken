<?php

declare(strict_types=1);

/*
 * Latency isolation under load (requirements §5.3).
 *
 *   php tests/E2E/load.php inline|queued|sync <rate> [seconds=10]
 *
 * Devices send ~400-byte QoS 1 reports at <rate> messages per second over
 * 1000 topics; each report is parsed, its HMAC checked and in-memory state
 * updated. 5 reports per second go to a handler that blocks for 50 ms.
 * Meanwhile the application queues 20 commands per second in the outbox
 * stream, and a subscriber measures how long each takes to reach it.
 *
 *   inline  Harken, slow handler runs on the event loop
 *   queued  Harken, slow handler is ->queued() (a queue:work process runs it)
 *   sync    a synchronous loop doing the same work (tests/E2E/Load/sync-loop.php)
 *
 * Reports: inbound sent / handled, outbox publish latency p50 / p99 / max,
 * commands lost, CPU of the runtime process. Needs docker/compose.yml.
 */

use React\EventLoop\Loop;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Runtime\Redis\RedisClient;
use Whatef\Harken\Runtime\Redis\RedisEndpoint;
use Whatef\Harken\Tests\E2E\Load\DeviceReport;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;

require __DIR__.'/../../vendor/autoload.php';

$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$root = dirname(__DIR__, 2);

$connect = static function (string $id) use ($host): Connection {
    $connection = new Connection(
        new ConnectionOptions('load', new Endpoint($host), $id, new ExponentialBackoff, keepAlive: 60),
        new ReactSocketTransport(Loop::get()),
        ProtocolVersion::V5_0->codec(),
        Loop::get(),
    );
    await($connection->connect());

    return $connection;
};

// ------------------------------------------------------------ generator
if (($argv[1] ?? null) === 'generate') {
    [, , $rate, $seconds] = $argv;
    $rate = (int) $rate;
    $publisher = $connect('harken-e2e-gen-'.bin2hex(random_bytes(3)));
    $payloads = array_map(DeviceReport::payload(...), range(0, 99));
    $sent = 0;
    $slow = 0;
    $total = (int) ($rate * (float) $seconds);
    $start = microtime(true);
    $done = false;

    // Every 10 ms, catch up with the schedule.
    Loop::addPeriodicTimer(0.01, static function ($timer) use (&$sent, &$slow, &$done, $total, $rate, $start, $publisher, $payloads): void {
        $due = min($total, (int) ((microtime(true) - $start) * $rate));

        while ($sent < $due) {
            $publisher->publish('load/'.($sent % 1000).'/up', $payloads[$sent % 100], 1);
            $sent++;

            if ($sent % max(1, intdiv($rate, 5)) === 0) {
                $publisher->publish('load/'.($sent % 1000).'/slow', '{}', 1);
                $slow++;
            }
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
    echo json_encode(['sent' => $sent, 'slow' => $slow]), "\n";
    $publisher->disconnect();
    exit(0);
}

// ------------------------------------------------------------ orchestrator
$target = $argv[1] ?? 'inline';
$rate = (int) ($argv[2] ?? 1000);
$seconds = (float) ($argv[3] ?? 10);
$run = bin2hex(random_bytes(4));
$dir = sys_get_temp_dir()."/harken-load-{$run}";
mkdir($dir);
$prefix = 'e2e_';
$stream = "{$prefix}harken:outbox:normal";

$env = [
    'HARKEN_HOST' => $host,
    'HARKEN_LEADER_LOCK' => 'none',
    'HARKEN_LEADER_CLIENT_ID' => "harken-e2e-{$run}-leader",
    'HARKEN_LOG_CHANNEL' => 'null',
    'REDIS_PREFIX' => $prefix,
    'QUEUE_CONNECTION' => 'redis',
    'E2E_MODE' => 'load',
    'E2E_SLOW' => $target === 'queued' ? 'queued' : 'inline',
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

$redis = new RedisClient(new RedisEndpoint('127.0.0.1'), Loop::get());
await($redis->call('del', $stream, "{$prefix}harken:outbox:urgent"));

// The process under test.
if ($target === 'sync') {
    [$process, $pid] = $spawn(['tests/E2E/Load/sync-loop.php', (string) ($seconds + 8), $stream, 'harken'], 'target');
    $ready = $waitFor("{$dir}/target.out", 'ready', 15);
} else {
    [$process, $pid] = $spawn(['tests/E2E/artisan', 'harken:start'], 'target');
    $ready = $waitFor("{$dir}/target.out", 'Leading', 15);
}

if (! $ready) {
    fwrite(STDERR, "The {$target} target did not start.\n".file_get_contents("{$dir}/target.out").file_get_contents("{$dir}/target.err"));
    exit(1);
}

$worker = $target === 'queued' ? $spawn(['tests/E2E/artisan', 'queue:work', 'redis', '--sleep=0.01'], 'worker')[0] : null;

// The subscriber for outbox commands.
$latencies = [];
$subscriber = new Connection(
    new ConnectionOptions('load', new Endpoint($host), "harken-e2e-{$run}-sub", new ExponentialBackoff),
    new ReactSocketTransport(Loop::get()),
    ProtocolVersion::V5_0->codec(),
    Loop::get(),
);
$subscriber->subscribe(["load-cmd/{$run}/#" => 1]);
$subscriber->onMessage(static function (Message $message) use (&$latencies): void {
    $latencies[(int) explode('/', $message->topic)[2]] = (microtime(true) - (float) $message->payload) * 1000;
});
await($subscriber->connect());
delay(1.0);

// Load, commands and CPU over the run.
$cpuStart = $cpu($pid);
$wallStart = microtime(true);
[$generator] = $spawn(['tests/E2E/load.php', 'generate', (string) $rate, (string) $seconds], 'generator');
$commands = 0;
$commandTimer = Loop::addPeriodicTimer(0.05, static function () use (&$commands, $redis, $stream, $run): void {
    $message = new OutboxMessage("load-cmd/{$run}/{$commands}", sprintf('%.6f', microtime(true)), 1);
    $fields = [];

    foreach ($message->toFields() as $field => $value) {
        $fields[] = $field;
        $fields[] = $value;
    }

    $redis->call('xadd', $stream, '*', ...$fields);
    $commands++;
});

while (proc_get_status($generator)['running']) {
    delay(0.1);
}

$cpuUsed = $cpu($pid) - $cpuStart;
$wall = microtime(true) - $wallStart;
Loop::cancelTimer($commandTimer);
delay(6.5); // past the next status heartbeat (every 5 s), so its counters include the backlog

$generated = json_decode((string) file_get_contents("{$dir}/generator.out"), true) ?: ['sent' => 0, 'slow' => 0];

// Inbound messages handled.
if ($target === 'sync') {
    posix_kill($pid, SIGTERM);
    $waitFor("{$dir}/target.out", '"received"', 15);
    $lines = file("{$dir}/target.out", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $handled = (int) (json_decode((string) end($lines), true)['received'] ?? 0);
} else {
    [$status] = $spawn(['tests/E2E/artisan', 'harken:status', '--json'], 'status');

    while (proc_get_status($status)['running']) {
        delay(0.05);
    }

    $json = (string) file_get_contents("{$dir}/status.out");
    $statuses = json_decode(substr($json, 0, (int) strrpos($json, ']') + 1), true) ?: [];
    $mine = array_values(array_filter($statuses, static fn (array $s): bool => ($s['pid'] ?? null) === $pid))[0] ?? [];
    $handled = (int) ($mine['leader']['mqtt']['received'] ?? 0);
    posix_kill($pid, SIGTERM);
}

if ($worker !== null) {
    proc_terminate($worker);
}

$subscriber->disconnect();
$redis->close();

// End the leader session, so the broker stops queuing load messages for it.
if ($target !== 'sync') {
    delay(1.0);
    $cleanup = $connect($env['HARKEN_LEADER_CLIENT_ID']);
    await($cleanup->disconnect());
}

$expected = $generated['sent'] + $generated['slow'];
$values = array_values($latencies);

echo json_encode([
    'target' => $target,
    'rate' => $rate,
    'seconds' => $seconds,
    'inbound_sent' => $expected,
    'inbound_handled' => $handled,
    'inbound_lost' => max(0, $expected - $handled),
    'commands_sent' => $commands,
    'commands_lost' => $commands - count($latencies),
    'command_latency_ms' => ['p50' => $percentile($values, 0.5), 'p99' => $percentile($values, 0.99), 'max' => $percentile($values, 1.0)],
    'cpu_percent' => round($cpuUsed / $wall * 100, 1),
    'logs' => $dir,
], JSON_UNESCAPED_SLASHES), "\n";
