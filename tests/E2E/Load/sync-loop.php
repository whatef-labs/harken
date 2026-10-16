<?php

declare(strict_types=1);

/*
 * The synchronous baseline for load.php: one blocking loop that receives,
 * runs every handler inline, and in between takes delegated publishes from
 * the Redis stream and publishes them QoS 1, waiting for each PUBACK (as a
 * synchronous MQTT client does). A slow handler holds up everything else.
 *
 *   php tests/E2E/Load/sync-loop.php <seconds> <stream> <group>
 *
 * Prints {"received": n, "published": n} when done or on SIGTERM.
 */

use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Protocol\Packet\PingReq;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\Packet\SubAck;
use Whatef\Harken\Protocol\Packet\Subscribe;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Tests\E2E\Load\DeviceReport;
use Whatef\Harken\Tests\E2E\Load\SlowReport;

require __DIR__.'/../../../vendor/autoload.php';

[, $seconds, $stream, $group] = $argv;
$host = getenv('HARKEN_TEST_BROKER_HOST') ?: '127.0.0.1';
$codec = new Mqtt5Codec;
$decoder = $codec->decoder();
$socket = stream_socket_client("tcp://{$host}:1883", $errno, $error, 5.0) ?: throw new RuntimeException($error);
stream_set_blocking($socket, false);
$inbox = [];
$received = 0;
$published = 0;
$running = true;
$light = new DeviceReport;
$slow = new SlowReport;

pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void {
    $running = false;
});

$send = static function (Packet $packet) use ($socket, $codec): void {
    $bytes = $codec->encode($packet);

    while ($bytes !== '') {
        $written = fwrite($socket, $bytes);
        $bytes = substr($bytes, $written ?: 0);
    }
};

/** Reads whatever is there (waiting up to $timeoutUs) and queues the packets. */
$read = static function (int $timeoutUs) use ($socket, $decoder, &$inbox): void {
    $r = [$socket];
    $w = $e = null;

    if (@stream_select($r, $w, $e, 0, $timeoutUs) > 0) {
        $data = fread($socket, 65536);

        if ($data !== false && $data !== '') {
            array_push($inbox, ...$decoder->push($data));
        }
    }
};

$handle = static function (Publish $packet) use ($send, $light, $slow, &$received): void {
    $message = new Message($packet->topic, $packet->payload, $packet->qos, $packet->retain, ['id' => explode('/', $packet->topic)[1] ?? '']);
    str_ends_with($packet->topic, '/slow') ? $slow($message) : $light($message);
    $received++;

    if ($packet->qos === 1 && $packet->packetId !== null) {
        $send(new PubAck($packet->packetId));
    }
};

$send(new Connect('harken-e2e-sync-'.bin2hex(random_bytes(3)), 60));
while (! array_filter($inbox, static fn ($p) => $p instanceof ConnAck)) {
    $read(100000);
}
$inbox = [];
$send(new Subscribe(1, ['load/+/up' => 1, 'load/+/slow' => 1]));
while (! array_filter($inbox, static fn ($p) => $p instanceof SubAck)) {
    $read(100000);
}
$inbox = [];

$redis = new Redis;
$redis->connect('127.0.0.1');

try {
    $redis->xGroup('CREATE', $stream, $group, '0', true);
} catch (RedisException) {
    // BUSYGROUP
}

fwrite(STDOUT, "ready\n");
$deadline = microtime(true) + (float) $seconds;
$nextPing = microtime(true) + 30;
$packetId = 100;

while ($running && microtime(true) < $deadline) {
    $read(2000); // the loop waits 2 ms for traffic

    while ($inbox !== []) {
        $packet = array_shift($inbox);

        if ($packet instanceof Publish) {
            $handle($packet);
        }
    }

    $entries = $redis->xReadGroup($group, 'sync', [$stream => '>'], 10) ?: [];

    foreach ($entries[$stream] ?? [] as $id => $fields) {
        $packetId = $packetId >= 65535 ? 100 : $packetId + 1;
        $send(new Publish($fields['topic'], $fields['payload'], 1, false, false, $packetId));

        // Wait for this PUBACK, handling whatever else arrives meanwhile.
        $acked = false;

        while (! $acked) {
            $read(2000);

            while ($inbox !== []) {
                $packet = array_shift($inbox);

                if ($packet instanceof PubAck && $packet->packetId === $packetId) {
                    $acked = true;
                } elseif ($packet instanceof Publish) {
                    $handle($packet);
                }
            }
        }

        $redis->xAck($stream, $group, [$id]);
        $redis->xDel($stream, [$id]);
        $published++;
    }

    if (microtime(true) >= $nextPing) {
        $send(new PingReq);
        $nextPing = microtime(true) + 30;
    }
}

echo json_encode(['received' => $received, 'published' => $published]), "\n";
