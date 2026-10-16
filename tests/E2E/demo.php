<?php

declare(strict_types=1);

/*
 * Traffic for the Grafana dashboard (docker/monitoring):
 *
 *   docker compose -f docker/monitoring/compose.yml up -d
 *   php tests/E2E/demo.php [seconds=180] [rate=500]
 *
 * Starts two harken:start replicas with the prometheus metrics driver (ports
 * 9180 and 9181) and a leader route, sends device reports at <rate> per
 * second and ten outbox commands per second, restarts the leader gracefully
 * (like a deployment) a third of the way in and kills the new leader
 * (like a crash) two thirds of the way in. Needs docker/compose.yml.
 */

use Whatef\Harken\Runtime\Outbox\OutboxMessage;

require __DIR__.'/../../vendor/autoload.php';

$seconds = (int) ($argv[1] ?? 180);
$rate = (int) ($argv[2] ?? 500);
$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$root = dirname(__DIR__, 2);
$dir = sys_get_temp_dir().'/harken-demo';
@mkdir($dir);
$prefix = 'demo_';

$env = [
    'HARKEN_HOST' => $host,
    'HARKEN_METRICS_DRIVER' => 'prometheus',
    'HARKEN_LEADER_LOCK' => 'redis',
    'HARKEN_LEADER_CLIENT_ID' => 'harken-demo-leader',
    'HARKEN_LOG_CHANNEL' => 'null',
    'REDIS_PREFIX' => $prefix,
    'E2E_MODE' => 'load',
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

$leader = static function () use (&$replicas): ?int {
    foreach (array_keys($replicas) as $port) {
        if (preg_match('/^harken_leader 1$/m', (string) @file_get_contents("http://127.0.0.1:{$port}/metrics")) === 1) {
            return $port;
        }
    }

    return null;
};

$stop = static function (int $port, int $signal) use (&$replicas): void {
    posix_kill(proc_get_status($replicas[$port])['pid'], $signal);

    for ($i = 0; $i < 100 && proc_get_status($replicas[$port])['running']; $i++) {
        usleep(100_000);
    }

    proc_close($replicas[$port]);
    unset($replicas[$port]);
};

$start(9180);
sleep(3);
$start(9181);
sleep(2);

$generator = proc_open(
    [PHP_BINARY, 'tests/E2E/bench.php', 'generate', (string) $rate, (string) $seconds, '0'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/generator.out", 'w'], 2 => ['file', "{$dir}/generator.err", 'w']],
    $pipes,
    $root,
    $env,
);

$redis = new Redis;
$redis->connect('127.0.0.1');
$began = time();
$events = [(int) ($seconds / 3) => 'restart', (int) ($seconds * 2 / 3) => 'crash'];

for ($i = 0; time() - $began < $seconds; $i++) {
    $elapsed = time() - $began;

    if (isset($events[$elapsed])) {
        $event = $events[$elapsed];
        unset($events[$elapsed]);
        $port = $leader();

        if ($port !== null) {
            echo "{$elapsed} s: {$event} of the leader on port {$port}\n";
            $stop($port, $event === 'restart' ? SIGTERM : SIGKILL);
            $start($port);
        }
    }

    $stream = $i % 10 === 0 ? 'harken:outbox:urgent' : 'harken:outbox:normal';
    $fields = (new OutboxMessage("demo/cmd/{$i}", '{"open":true}', 1))->toFields();
    $redis->rawCommand('XADD', $prefix.$stream, '*', ...array_merge(...array_map(null, array_keys($fields), array_values($fields))));
    usleep(100_000);
}

proc_close($generator);

foreach (array_keys($replicas) as $port) {
    $stop($port, SIGTERM);
}

echo "done\n";
