<?php

declare(strict_types=1);

/*
 * Spike 03 — Redis Streams outbox on the event loop.
 *
 *   php spikes/03-redis-outbox.php            run all experiments
 *   php spikes/03-redis-outbox.php produce …  (internal) producer subprocess
 *
 * Env: SPIKE_REDIS_HOST (127.0.0.1), SPIKE_REDIS_PORT (6379)
 *
 * The broker publish is simulated by a 2 ms timer standing in for the
 * PUBLISH -> PUBACK round trip. Spike 01 replaces it with a real broker.
 */

use Clue\React\Redis\Client as RedisClient;
use Clue\React\Redis\Factory as RedisFactory;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Transport\Endpoint;

use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;
use function React\Promise\Timer\sleep as delay;

require __DIR__.'/support.php';

$host = getenv('SPIKE_REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SPIKE_REDIS_PORT') ?: 6379);
$urgent = 'harken:spike:urgent';
$normal = 'harken:spike:normal';
$group = 'harken';

// ---------------------------------------------------------------- producer
if (($argv[1] ?? null) === 'produce') {
    [, , $stream, $count, $rate] = $argv;
    $redis = new Redis;
    $redis->connect($host, $port);
    $interval = 1 / (float) $rate;
    $xadd = [];
    $start = microtime(true);

    for ($i = 0; $i < (int) $count; $i++) {
        $due = $start + $i * $interval;
        while (($now = microtime(true)) < $due) {
            usleep(min(1000, (int) (($due - $now) * 1e6)));
        }
        $t = hrtime(true);
        $redis->xAdd($stream, '*', [
            'topic' => "cmd/{$i}", 'payload' => random_bytes(64), 'qos' => '1', 'retain' => '0',
            'ts' => (string) (int) (microtime(true) * 1e6),
        ]);
        $xadd[] = spike_ms($t);
    }

    fwrite(STDOUT, json_encode(spike_percentiles($xadd)));
    exit(0);
}

// ---------------------------------------------------------------- setup
$sync = new Redis;
$sync->connect($host, $port);
spike_line('redis server', $sync->info('server')['redis_version']);
spike_line('event loop', (new ReflectionClass(Loop::get()))->getShortName());

$reset = static function () use ($sync, $urgent, $normal, $group): void {
    $sync->del($urgent, $normal);
    foreach ([$urgent, $normal] as $stream) {
        $sync->xGroup('CREATE', $stream, $group, '$', true);
    }
};

$factory = new RedisFactory;
$uri = "redis://{$host}:{$port}";
/** @var RedisClient $reader dedicated connection for blocking reads */
$reader = await($factory->createClient($uri));
/** @var RedisClient $acker second connection for XACK / XAUTOCLAIM */
$acker = await($factory->createClient($uri));

/** Stand-in for publish(QoS 1) resolving on PUBACK. */
$publish = static fn (): PromiseInterface => delay(0.002);

/**
 * Parses an XREADGROUP / XAUTOCLAIM entry list into [id => fields].
 *
 * @return array<string, array<string, string>>
 */
$entries = static function (array $list): array {
    $out = [];
    foreach ($list as [$id, $flat]) {
        $fields = [];
        for ($i = 0; $i < count($flat); $i += 2) {
            $fields[$flat[$i]] = $flat[$i + 1];
        }
        $out[$id] = $fields;
    }

    return $out;
};

// ------------------------------------------------- E1 head-of-line blocking
echo "\nE1  same connection vs dedicated connection during XREADGROUP BLOCK 1500\n";
$reset();
$t = hrtime(true);
$blocked = $reader->xreadgroup('GROUP', $group, 'probe', 'BLOCK', 1500, 'STREAMS', $normal, '>');
$samePing = $reader->ping()->then(fn () => spike_ms($t));
$otherPing = $acker->ping()->then(fn () => spike_ms($t));
[, $sameMs, $otherMs] = await(all([$blocked, $samePing, $otherPing]));
spike_line('PING on the blocking connection (ms)', round($sameMs, 1));
spike_line('PING on a second connection (ms)', round($otherMs, 1));

// ------------------------------------------------- E2 loop lag while blocking
echo "\nE2  1 ms timer lag (ms) — idle loop vs loop with a pending BLOCK read\n";
spike_line('idle loop', spike_loop_lag(Loop::get(), 2.0));
$reset();
$stop = false;
$blockLoop = async(function () use (&$stop, $reader, $group, $normal) {
    while (! $stop) {
        await($reader->xreadgroup('GROUP', $group, 'lag', 'COUNT', 100, 'BLOCK', 500, 'STREAMS', $normal, '>'));
    }
});
$pending = $blockLoop();
spike_line('with XREADGROUP BLOCK 500 in a loop', spike_loop_lag(Loop::get(), 2.0));
$stop = true;
await($pending);

// ------------------------------------------------- E3 consume -> publish -> ack
$consume = static function (string $consumer, int $expect, float $timeout, bool $ack = true, ?Closure $publisher = null) use ($reader, $acker, $group, $urgent, $normal, $publish, $entries): array {
    $publish = $publisher ?? $publish;

    return await(async(function () use ($consumer, $expect, $timeout, $ack, $reader, $acker, $group, $urgent, $normal, $publish, $entries) {
        $latency = ['urgent' => [], 'normal' => []];
        $seen = 0;
        $deadline = microtime(true) + $timeout;
        $inflight = [];

        while ($seen < $expect && microtime(true) < $deadline) {
            $reply = await($reader->xreadgroup('GROUP', $group, $consumer, 'COUNT', 200, 'BLOCK', 200, 'STREAMS', $urgent, $normal, '>', '>'));
            if ($reply === null) {
                continue;
            }
            // urgent first: the reply lists streams in request order
            foreach ($reply as [$stream, $list]) {
                foreach ($entries($list) as $id => $fields) {
                    $seen++;
                    $kind = $stream === $urgent ? 'urgent' : 'normal';
                    $inflight[] = $publish($fields)->then(function () use ($ack, $acker, $stream, $group, $id, $fields, &$latency, $kind) {
                        if (! $ack) {
                            return null;
                        }

                        return $acker->xack($stream, $group, $id)->then(function () use ($fields, &$latency, $kind) {
                            $latency[$kind][] = (microtime(true) * 1e6 - (int) $fields['ts']) / 1000;
                        });
                    });
                }
            }
        }
        await(all($inflight));

        return ['seen' => $seen, 'urgent' => spike_percentiles($latency['urgent']), 'normal' => spike_percentiles($latency['normal'])];
    })());
};

$produce = static function (string $stream, int $count, int $rate) use ($argv) {
    $process = proc_open([PHP_BINARY, $argv[0], 'produce', $stream, (string) $count, (string) $rate], [1 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);

    return [$process, $pipes[1]];
};

$finish = static function (array $producer): array {
    [$process, $out] = $producer;
    stream_set_blocking($out, true);
    $json = stream_get_contents($out);
    proc_close($process);

    return json_decode((string) $json, true) ?? [];
};

foreach ([1000, 5000] as $rate) {
    echo "\nE3  {$rate} entries/s for 3 s from another process: XADD -> XREADGROUP -> publish(2 ms) -> XACK\n";
    $reset();
    $producer = $produce($normal, $rate * 3, $rate);
    $result = $consume('c1', $rate * 3, 15.0);
    spike_line('producer XADD latency (ms)', $finish($producer));
    spike_line('entries consumed', $result['seen']);
    spike_line('enqueue -> XACK latency (ms)', $result['normal']);
    spike_line('XPENDING after run', $sync->xPending($normal, $group)[0] ?? null);
}

echo "\nE4  priority: 20000-entry normal backlog, then urgent entries at 20/s\n";
$reset();
$backlog = [];
for ($i = 0; $i < 20000; $i++) {
    $sync->xAdd($normal, '*', ['topic' => "bulk/{$i}", 'payload' => 'x', 'qos' => '1', 'retain' => '0', 'ts' => (string) (int) (microtime(true) * 1e6)]);
}
$producer = $produce($urgent, 40, 20);
$result = $consume('c1', 20040, 30.0);
spike_line('producer XADD latency (ms)', $finish($producer));
spike_line('urgent enqueue -> XACK (ms)', $result['urgent']);
spike_line('normal enqueue -> XACK (ms)', $result['normal']);

// ------------------------------------------------- E5 crash recovery
echo "\nE5  crash after publish, before XACK; restart and reclaim with XAUTOCLAIM\n";
$reset();
for ($i = 0; $i < 50; $i++) {
    $sync->xAdd($normal, '*', ['topic' => "cmd/{$i}", 'payload' => 'x', 'qos' => '1', 'retain' => '0', 'ts' => (string) (int) (microtime(true) * 1e6)]);
}
$lost = $consume('crashed', 50, 5.0, ack: false);
spike_line('read by crashed consumer, never acked', $lost['seen']);
spike_line('XPENDING (count) before restart', $sync->xPending($normal, $group)[0] ?? null);
await(delay(0.3));

$reclaimed = 0;
$cursor = '0-0';
do {
    [$cursor, $list] = await($acker->xautoclaim($normal, $group, 'restarted', 200, $cursor, 'COUNT', 100));
    foreach ($entries($list) as $id => $fields) {
        await($publish());
        await($acker->xack($normal, $group, $id));
        $reclaimed++;
    }
} while ($cursor !== '0-0');
spike_line('reclaimed and acked by restarted consumer', $reclaimed);
spike_line('XPENDING (count) after reclaim', $sync->xPending($normal, $group)[0] ?? null);

// ------------------------------------------------- E6 B1 with a real broker
if (getenv('SPIKE_MQTT_HOST')) {
    require_once __DIR__.'/MiniClient.php';
    $run = bin2hex(random_bytes(3));
    $mqtt = await(MiniClient::connect(new Endpoint(getenv('SPIKE_MQTT_HOST'), (int) (getenv('SPIKE_MQTT_PORT') ?: 1883)), "spike03-{$run}"));
    $inbound = 0;
    $mqtt->onMessage = function (Publish $p) use (&$inbound) {
        $inbound++;
        hash_hmac('sha256', $p->payload, 'handler-key'); // a light inline handler
    };
    await($mqtt->subscribe(["spike/{$run}/inbound" => 1]));
    $realPublish = static fn (array $fields): PromiseInterface => $mqtt->publish($fields['topic'], $fields['payload'], 1);

    foreach ([0, 10000] as $inboundRate) {
        echo "\nE6  outbox -> EMQX QoS 1 (real PUBACK), urgent 100/s for 5 s, while receiving {$inboundRate} msg/s\n";
        $reset();
        $inbound = 0;
        $load = $inboundRate > 0
            ? proc_open([PHP_BINARY, __DIR__.'/01-connect-qos1-tls.php', 'publish', "spike/{$run}/inbound", (string) $inboundRate, '6'], [1 => ['pipe', 'w']], $loadPipes)
            : null;
        await(delay(0.5));
        $producer = $produce($urgent, 500, 100);
        $result = $consume('c1', 500, 30.0, true, $realPublish);
        spike_line('producer XADD latency (ms)', $finish($producer));
        spike_line('urgent enqueue -> PUBACK -> XACK (ms)', $result['urgent']);
        if ($load !== null) {
            stream_set_blocking($loadPipes[1], false);
            $output = '';
            while (! feof($loadPipes[1])) {
                $output .= (string) fread($loadPipes[1], 8192);
                await(delay(0.05));
            }
            $sent = json_decode($output, true)['sent'] ?? 0;
            proc_close($load);
            await(delay(1.0));
            spike_line('inbound received / sent', "{$inbound} / {$sent}");
        }
    }
    $mqtt->disconnect();
}

$sync->del($urgent, $normal);
$reader->close();
$acker->close();
