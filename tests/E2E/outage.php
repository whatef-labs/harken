<?php

declare(strict_types=1);

/*
 * Broker outage and recovery, end to end (C-05: after 30 s without the
 * broker the subscriptions are back within 60 s, and a retained message
 * published meanwhile arrives).
 *
 *   php tests/E2E/outage.php network|broker [outage-seconds=30]
 *
 * One `harken:start` replica with a leader route on harken-e2e/<run>/{kind}
 * receives QoS 1 messages ("live-N") at 20 per second.
 *
 * network: the replica reaches the broker through a TCP proxy in this
 *   script, which drops every connection and ends new ones at once for
 *   the outage. The broker keeps running: the live messages and a retained
 *   message published directly to it meanwhile must all arrive once the
 *   proxy is back (the leader's session is persistent).
 *
 * broker: the broker container is stopped for the outage and started
 *   again, losing every session. A retained message published right after
 *   it is back must arrive once the replica has reconnected and subscribed
 *   again, followed by every live message from then on. Needs the Docker
 *   socket mounted at /var/run/docker.sock; the container is named by
 *   HARKEN_TEST_BROKER_CONTAINER (harken-dev-emqx-1).
 *
 * Needs the broker and Redis from docker/compose.yml, ext-pcntl and
 * ext-posix. Env: HARKEN_TEST_BROKER_HOST (127.0.0.1).
 */

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Async\await;
use function React\Async\delay;

require __DIR__.'/../../vendor/autoload.php';

$mode = $argv[1] ?? 'network';
$outage = (float) ($argv[2] ?? 30);
$rate = 20; // the broker queues at most 1 000 messages per offline session by default
$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$container = getenv('HARKEN_TEST_BROKER_CONTAINER') ?: 'harken-dev-emqx-1';
$proxyPort = 18830;
$run = bin2hex(random_bytes(4));
$dir = sys_get_temp_dir()."/harken-outage-{$run}";
mkdir($dir);
$log = "{$dir}/received.log";
touch($log);

if (! in_array($mode, ['network', 'broker'], true)) {
    fwrite(STDERR, "Usage: php tests/E2E/outage.php network|broker [outage-seconds]\n");
    exit(2);
}

// ------------------------------------------------------------ proxy (network)
/** @var list<array{ConnectionInterface, ConnectionInterface}> $pipes */
$pipes = [];
$server = null;
$down = false;

$listen = static function () use (&$server, &$pipes, &$down, $host, $proxyPort): void {
    $server = new SocketServer("127.0.0.1:{$proxyPort}");
    $server->on('connection', static function (ConnectionInterface $client) use (&$pipes, &$down, $host): void {
        if ($down) {
            // No route to the broker: the connection ends before MQTT starts.
            $client->close();

            return;
        }

        $client->pause();
        (new Connector)->connect("{$host}:1883")->then(static function (ConnectionInterface $broker) use ($client, &$pipes): void {
            $client->pipe($broker);
            $broker->pipe($client);
            $client->on('close', $broker->close(...));
            $broker->on('close', $client->close(...));
            $client->resume();
            $pipes[] = [$client, $broker];
        }, $client->close(...));
    });
};

$cut = static function () use (&$pipes, &$down): void {
    $down = true;

    foreach ($pipes as [$client, $broker]) {
        $client->close();
        $broker->close();
    }

    $pipes = [];
};

// ------------------------------------------------------------ broker container (broker)
$docker = static function (string $action) use ($container): int {
    $socket = stream_socket_client('unix:///var/run/docker.sock', $errno, $error, 5);

    if ($socket === false) {
        throw new RuntimeException("Docker socket: {$error}");
    }

    fwrite($socket, "POST /containers/{$container}/{$action}?t=5 HTTP/1.0\r\nHost: docker\r\nContent-Length: 0\r\n\r\n");
    $response = (string) stream_get_contents($socket);
    fclose($socket);

    return (int) substr($response, 9, 3);
};

// ------------------------------------------------------------ helpers
$connect = static function (string $id) use ($host): Connection {
    $connection = new Connection(
        new ConnectionOptions('outage', new Endpoint($host), $id, new ExponentialBackoff(initial: 0.5, max: 2.0)),
        new ReactSocketTransport(Loop::get()),
        ProtocolVersion::V5_0->codec(),
        Loop::get(),
    );

    // The broker may still be starting: retry for up to a minute.
    for ($deadline = microtime(true) + 60; ; delay(0.5)) {
        try {
            await($connection->connect());

            return $connection;
        } catch (Throwable $e) {
            if (microtime(true) > $deadline) {
                throw $e;
            }
        }
    }
};

$waitFor = static function (string $file, string $needle, float $timeout): bool {
    for ($deadline = microtime(true) + $timeout; microtime(true) < $deadline; delay(0.05)) {
        if (str_contains((string) @file_get_contents($file), $needle)) {
            return true;
        }
    }

    return false;
};

/** @return array<string, float> payload => first time received */
$received = static function () use ($log): array {
    $seen = [];

    foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [, $payload, $time] = explode(' ', $line) + [null, null, null];

        if ($payload !== 'timer' && $payload !== null) {
            $seen[$payload] ??= (float) $time;
        }
    }

    return $seen;
};

// ------------------------------------------------------------ run
if ($mode === 'network') {
    $listen();
}

$env = [
    'HARKEN_HOST' => $mode === 'network' ? '127.0.0.1' : $host,
    'HARKEN_PORT' => $mode === 'network' ? (string) $proxyPort : '1883',
    'HARKEN_LEADER_LOCK' => 'redis',
    'HARKEN_LEADER_CLIENT_ID' => "harken-outage-{$run}-leader",
    'HARKEN_OUTBOX' => '0',
    'E2E_TOPIC' => "harken-e2e/{$run}/{kind}",
    'E2E_LOG' => $log,
] + getenv();

$replica = proc_open(
    [PHP_BINARY, 'tests/E2E/artisan', 'harken:start'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/replica.out", 'w'], 2 => ['file', "{$dir}/replica.err", 'w']],
    $pipesOfReplica,
    dirname(__DIR__, 2),
    $env,
);

if (! $waitFor("{$dir}/replica.out", 'Leading', 15)) {
    fwrite(STDERR, "The replica did not become leader.\n".file_get_contents("{$dir}/replica.out").file_get_contents("{$dir}/replica.err"));
    exit(1);
}

delay(1.0);
$publisher = $connect("harken-outage-{$run}-pub");
$sent = 0;
$sending = true;
$timer = Loop::addPeriodicTimer(1 / $rate, static function () use (&$sent, &$sending, &$publisher, $run): void {
    if ($sending) {
        $publisher->publish("harken-e2e/{$run}/live", 'live-'.$sent++, 1)->then(null, static fn () => null);
    }
});

delay(3.0);
$outageStart = microtime(true);

if ($mode === 'network') {
    $cut();
    delay($outage / 2);
    // Published while the replica cannot reach the broker.
    await($publisher->publish("harken-e2e/{$run}/retained", 'retained', 1, retain: true));
    delay($outage / 2);
    $down = false;
    $restored = microtime(true);
} else {
    $sending = false;
    delay(0.2);
    await($publisher->disconnect());
    $stopStatus = $docker('stop');
    delay(max(0.0, $outage - (microtime(true) - $outageStart)));
    $startStatus = $docker('start');
    $publisher = $connect("harken-outage-{$run}-pub2");
    $restored = microtime(true);
    // Retained by the broker before the replica is back.
    await($publisher->publish("harken-e2e/{$run}/retained", 'retained', 1, retain: true));
    $firstAfter = $sent;
    $sending = true;
}

$retainedAt = null;

for ($deadline = $restored + 60; microtime(true) < $deadline; delay(0.2)) {
    if (($retainedAt = $received()['retained'] ?? null) !== null) {
        break;
    }
}

// Keep traffic going a little, then let everything in flight arrive.
delay(5.0);
Loop::cancelTimer($timer);
$total = $sent;
delay(3.0);

posix_kill(proc_get_status($replica)['pid'], SIGTERM);

for ($i = 0; $i < 200 && proc_get_status($replica)['running']; $i++) {
    delay(0.05);
}

$runningUntilStopped = ! str_contains(file_get_contents("{$dir}/replica.out").file_get_contents("{$dir}/replica.err"), 'cannot continue');
proc_close($replica);

// Clear the retained message and end the leader session.
await($publisher->publish("harken-e2e/{$run}/retained", '', 1, retain: true));
await($publisher->disconnect());
await($connect("harken-outage-{$run}-leader")->disconnect());
$cut();
$server?->close();

// ------------------------------------------------------------ analyse
$seen = $received();
$live = array_filter($seen, static fn (string $payload): bool => str_starts_with($payload, 'live-'), ARRAY_FILTER_USE_KEY);
$liveAfterRestore = array_filter($live, static fn (float $time): bool => $time >= $restored);
$firstAfterRestore = $liveAfterRestore === [] ? null : min($liveAfterRestore);

if ($mode === 'network') {
    $expected = range(0, $total - 1);
} else {
    // The broker lost the session: what was published before the replica
    // subscribed again had no subscriber. From the first message it got
    // after the restart on, nothing may be missing.
    $afterRestart = array_filter(array_keys($liveAfterRestore), static fn (string $payload): bool => (int) substr($payload, 5) >= $firstAfter);
    $firstSeq = $afterRestart === [] ? $total : min(array_map(static fn (string $payload): int => (int) substr($payload, 5), $afterRestart));
    $expected = range($firstSeq, $total - 1);
}

$lost = array_values(array_filter($expected, static fn (int $seq): bool => ! isset($live["live-{$seq}"])));

$result = [
    'mode' => $mode,
    'outage_seconds' => $outage,
    'live_sent' => $total,
    'live_expected' => count($expected),
    'live_lost' => count($lost),
    'retained_received' => $retainedAt !== null,
    'retained_after_restore_seconds' => $retainedAt === null ? null : round($retainedAt - $restored, 3),
    'first_live_after_restore_seconds' => $firstAfterRestore === null ? null : round($firstAfterRestore - $restored, 3),
    'replica_kept_running' => $runningUntilStopped,
    'docker' => $mode === 'broker' ? ['stop' => $stopStatus ?? null, 'start' => $startStatus ?? null] : null,
    'logs' => $dir,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$pass = $lost === [] && $retainedAt !== null && $retainedAt - $restored <= 60 && $runningUntilStopped && count($expected) > 0;

echo $pass ? "PASS\n" : "FAIL\n";
exit($pass ? 0 : 1);
