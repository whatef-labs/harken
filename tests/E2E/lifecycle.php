<?php

declare(strict_types=1);

/*
 * Runtime life cycle, end to end:
 *
 *   php tests/E2E/lifecycle.php
 *
 * 1. `harken:start` comes up and `harken:status` reports it healthy;
 * 2. an entry queued in the outbox stream is published to the broker;
 * 3. `harken:restart` makes the runtime stop gracefully with exit code 0;
 * 4. `harken:status` then fails, and `--max-time` stops a runtime by itself.
 *
 * Needs the broker and Redis from docker/compose.yml.
 */

use React\EventLoop\Loop;
use React\Promise\Deferred;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\Timer\timeout;

require __DIR__.'/../../vendor/autoload.php';

$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$run = bin2hex(random_bytes(4));
$dir = sys_get_temp_dir()."/harken-e2e-{$run}";
mkdir($dir);
$root = dirname(__DIR__, 2);
$checks = [];

$env = [
    'HARKEN_HOST' => $host,
    'HARKEN_LEADER_LOCK' => 'none',
    'HARKEN_LEADER_CLIENT_ID' => "harken-e2e-{$run}-leader",
    'E2E_TOPIC' => "harken-e2e/{$run}/in",
    'E2E_LOG' => "{$dir}/received.log",
] + getenv();

$artisan = static function (array $arguments, string $name) use ($env, $dir, $root): array {
    $process = proc_open(
        [PHP_BINARY, 'tests/E2E/artisan', ...$arguments],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/{$name}.out", 'w'], 2 => ['file', "{$dir}/{$name}.err", 'w']],
        $pipes,
        $root,
        $env,
    );

    return [$process, "{$dir}/{$name}.out"];
};

$wait = static function ($process, float $seconds): ?int {
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);

        if (! $status['running']) {
            return $status['exitcode'];
        }

        delay(0.05);
    }

    return null;
};

$check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = sprintf('%s %s%s', $ok ? 'ok  ' : 'FAIL', $name, $detail === '' ? '' : " ({$detail})");
};

// A subscriber for what the runtime publishes from the outbox.
$received = new Deferred;
$subscriber = new Connection(
    new ConnectionOptions('e2e', new Endpoint($host), "harken-e2e-{$run}-sub", new ExponentialBackoff),
    new ReactSocketTransport(Loop::get()),
    ProtocolVersion::V5_0->codec(),
    Loop::get(),
);
$subscriber->subscribe(["harken-e2e/{$run}/out" => 1]);
$subscriber->onMessage(fn (Message $message) => $received->resolve($message->payload));
await($subscriber->connect());

// 1. start and status
[$runtime, $out] = $artisan(['harken:start'], 'runtime');
delay(3.0);
[$status, $statusOut] = $artisan(['harken:status'], 'status');
$statusCode = $wait($status, 20);
$check('harken:status is healthy while running', $statusCode === 0, "exit {$statusCode}");
$check('harken:status shows the connection', str_contains((string) file_get_contents($statusOut), 'connected'));

// 2. outbox: Harken::publish() in another process queues, the runtime publishes
[$publish] = $artisan(['e2e:publish', "harken-e2e/{$run}/out", 'from the outbox'], 'publish');
$check('Harken::publish() outside the runtime succeeds', $wait($publish, 20) === 0);

try {
    $payload = await(timeout($received->promise(), 5.0));
    $check('an outbox entry reaches the broker', $payload === 'from the outbox');
} catch (Throwable $e) {
    $check('an outbox entry reaches the broker', false, $e->getMessage());
}

// 3. restart
$started = microtime(true);
[$restart] = $artisan(['harken:restart'], 'restart');
$wait($restart, 20);
$exit = $wait($runtime, 15);
$check('harken:restart stops the runtime gracefully', $exit === 0, sprintf('exit %s after %.1f s', var_export($exit, true), microtime(true) - $started));
$check('the runtime logged a graceful stop', str_contains((string) file_get_contents($out), 'Stopping (restart requested)'));

// 4. status after stop, and --max-time
[$status] = $artisan(['harken:status'], 'status-after');
$check('harken:status fails once nothing runs', $wait($status, 20) === 1);

[$timed] = $artisan(['harken:start', '--max-time=3'], 'timed');
$exit = $wait($timed, 25);
$check('--max-time stops the runtime by itself', $exit === 0, 'exit '.var_export($exit, true));

$subscriber->disconnect();

echo implode("\n", $checks), "\nlogs: {$dir}\n";
$failed = count(array_filter($checks, static fn (string $line): bool => str_starts_with($line, 'FAIL')));
echo $failed === 0 ? "PASS\n" : "FAIL\n";
exit($failed === 0 ? 0 : 1);
