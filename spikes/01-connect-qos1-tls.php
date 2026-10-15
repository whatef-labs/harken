<?php

declare(strict_types=1);

/*
 * Spike 01 — binsoul/net-mqtt packets over react/socket against a real broker:
 * connect, subscribe, QoS 1 publish/PUBACK, inbound ACK timing, retain, TLS.
 *
 *   php spikes/01-connect-qos1-tls.php
 *
 * Env: SPIKE_MQTT_HOST (127.0.0.1), SPIKE_MQTT_PORT (1883),
 *      SPIKE_MQTT_TLS_PORT (8883), SPIKE_MQTT_MTLS_PORT (8884),
 *      SPIKE_CERTS (docker/certs), SPIKE_MQTT_USERNAME, SPIKE_MQTT_PASSWORD
 */

use React\EventLoop\Loop;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\TlsOptions;

use function React\Async\await;
use function React\Promise\all;
use function React\Promise\Timer\sleep as delay;
use function React\Promise\Timer\timeout;

require __DIR__.'/support.php';
require __DIR__.'/MiniClient.php';

$host = getenv('SPIKE_MQTT_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SPIKE_MQTT_PORT') ?: 1883);
$tlsPort = (int) (getenv('SPIKE_MQTT_TLS_PORT') ?: 8883);
$mtlsPort = (int) (getenv('SPIKE_MQTT_MTLS_PORT') ?: 8884);
$certs = rtrim(getenv('SPIKE_CERTS') ?: __DIR__.'/../docker/certs', '/\\');
$user = getenv('SPIKE_MQTT_USERNAME') ?: null;
$pass = getenv('SPIKE_MQTT_PASSWORD') ?: null;
$run = bin2hex(random_bytes(3));
$plain = new Endpoint($host, $port);

$connect = static fn (Endpoint $e, string $id, bool $clean = true, int $keepAlive = 60) => MiniClient::connect($e, "spike01-{$run}-{$id}", $clean, $keepAlive, $user, $pass);

spike_line('broker', "{$host}:{$port} (tls {$tlsPort}, mtls {$mtlsPort})");
spike_line('run id', $run);
spike_line('platform', PHP_OS_FAMILY.' / PHP '.PHP_VERSION);

// ------------------------------------------------- E1 connect / subscribe
echo "\nE1  connect, subscribe, QoS 1 publish to own subscription\n";
$t = hrtime(true);
$client = await($connect($plain, 'main'));
spike_line('CONNECT -> CONNACK (ms)', round(spike_ms($t), 2));
$topic = "spike/{$run}/echo";
$received = [];
$client->onMessage = function (Publish $p) use (&$received) {
    $received[] = $p;
};
$suback = await($client->subscribe(["spike/{$run}/echo" => 1]));
spike_line('SUBACK return codes', $suback->returnCodes);
await($client->publish($topic, "\x00\x01raw\xff", 1));
await(delay(0.2));
spike_line('own message received', count($received) === 1 ? sprintf('yes (qos %d, packetId %s, bytes intact: %s)', $received[0]->qos, $received[0]->packetId, $received[0]->payload === "\x00\x01raw\xff" ? 'yes' : 'NO') : 'NO');

// ------------------------------------------------- E2 PUBACK round trip
echo "\nE2  QoS 1 PUBLISH -> PUBACK\n";
$received = [];
$rtt = [];
for ($i = 0; $i < 1000; $i++) {
    $t = hrtime(true);
    await($client->publish($topic, (string) $i, 1));
    $rtt[] = spike_ms($t);
}
spike_line('1000 sequential, RTT (ms)', spike_percentiles($rtt));

$received = [];
$window = 100;
$total = 10000;
$t = hrtime(true);
for ($sent = 0; $sent < $total; $sent += $window) {
    $batch = [];
    for ($j = 0; $j < $window; $j++) {
        $batch[] = $client->publish($topic, str_repeat('x', 400), 1);
    }
    await(all($batch));
}
$elapsed = spike_ms($t);
await(delay(0.5));
spike_line("{$total} x 400 B pipelined (window {$window})", sprintf('%.0f msg/s, all PUBACKed', $total / ($elapsed / 1000)));
spike_line('...echoed back to our subscription', count($received)." / {$total}");

// ------------------------------------------------- E3 inbound ACK timing
echo "\nE3  inbound QoS 1 without PUBACK, then reconnect\n";
foreach ([false => 'persistent session (clean_session = 0)', true => 'clean session (clean_session = 1)'] as $clean => $label) {
    $ackTopic = "spike/{$run}/ack/".(int) $clean;
    $sub = await($connect($plain, 'ack'.(int) $clean, (bool) $clean));
    $sub->autoAck = false;
    $first = [];
    $sub->onMessage = function (Publish $p) use (&$first) {
        $first[] = $p;
    };
    await($sub->subscribe([$ackTopic => 1]));
    for ($i = 0; $i < 5; $i++) {
        await($client->publish($ackTopic, "m{$i}", 1));
    }
    await(delay(0.3));
    $sub->kill(); // crash: no PUBACK, no DISCONNECT
    await(delay(0.3));

    $again = await($connect($plain, 'ack'.(int) $clean, (bool) $clean));
    $second = [];
    $again->onMessage = function (Publish $p) use (&$second) {
        $second[] = $p;
    };
    if ($clean) {
        await($again->subscribe([$ackTopic => 1]));
    }
    await(delay(1.0));
    spike_line($label, sprintf(
        'received %d unacked; after reconnect session_present=%s, redelivered %d (dup flag on %d)',
        count($first),
        $again->sessionPresent ? 'yes' : 'no',
        count($second),
        count(array_filter($second, fn (Publish $p) => $p->duplicate)),
    ));
    $again->disconnect();
    if (! $clean) {
        // remove the persistent session
        $cleanup = await($connect($plain, 'ack0', true));
        $cleanup->disconnect();
    }
}

// ------------------------------------------------- E4 retain
echo "\nE4  retained publish and clearing it\n";
$state = "spike/{$run}/state";
await($client->publish($state, 'v1', 1, true));
$probe = static function () use ($connect, $plain, $state): array {
    $c = await($connect($plain, 'probe'.bin2hex(random_bytes(2))));
    $got = [];
    $c->onMessage = function (Publish $p) use (&$got) {
        $got[] = $p;
    };
    await($c->subscribe([$state => 1]));
    await(delay(0.5));
    $c->disconnect();

    return $got;
};
$got = $probe();
spike_line('new subscriber after retained publish', count($got) === 1 ? sprintf("got '%s' (retain flag %s)", $got[0]->payload, $got[0]->retain ? 'set' : 'NOT set') : count($got).' messages');
await($client->publish($state, '', 1, true));
spike_line('new subscriber after empty retained publish', count($probe()).' messages');

// ------------------------------------------------- E5 TLS
echo "\nE5  TLS\n";
$try = static function (string $label, Endpoint $endpoint) use ($connect): void {
    try {
        $t = hrtime(true);
        $c = await(timeout($connect($endpoint, 'tls'.bin2hex(random_bytes(2))), 10));
        $handshake = spike_ms($t);
        $rtt = [];
        for ($i = 0; $i < 200; $i++) {
            $s = hrtime(true);
            await($c->publish('spike/tls', 'x', 1));
            $rtt[] = spike_ms($s);
        }
        $c->disconnect();
        spike_line($label, sprintf('connected in %.1f ms, QoS 1 RTT %s', $handshake, json_encode(spike_percentiles($rtt))));
    } catch (Throwable $e) {
        spike_line($label, 'FAILED: '.strtok($e->getMessage(), "\n"));
    }
};
$try("{$tlsPort} with private CA", new Endpoint($host, $tlsPort, new TlsOptions(caFile: "{$certs}/ca.crt")));
$try("{$tlsPort} with system CAs (expect failure)", new Endpoint($host, $tlsPort, new TlsOptions));
$try("{$mtlsPort} mutual TLS with client cert", new Endpoint($host, $mtlsPort, new TlsOptions(caFile: "{$certs}/ca.crt", certFile: "{$certs}/client.crt", keyFile: "{$certs}/client.key")));
$try("{$mtlsPort} mutual TLS without client cert (expect failure)", new Endpoint($host, $mtlsPort, new TlsOptions(caFile: "{$certs}/ca.crt")));

// ------------------------------------------------- E6 keepalive
echo "\nE6  keepalive = 4 s\n";
$idle = await($connect($plain, 'ka-idle', true, 4));
$closedAt = null;
$t = hrtime(true);
$idle->onClose = function () use (&$closedAt, $t) {
    $closedAt = spike_ms($t);
};
$pinging = await($connect($plain, 'ka-ping', true, 4));
$pingRtt = [];
for ($i = 0; $i < 5; $i++) {
    await(delay(2.0));
    $s = hrtime(true);
    await($pinging->ping());
    $pingRtt[] = spike_ms($s);
}
spike_line('silent client closed by broker after (ms)', $closedAt === null ? 'still open after 10 s' : round($closedAt));
spike_line('client pinging every 2 s', $pinging->isClosed() ? 'closed' : 'still connected; PINGRESP '.json_encode(spike_percentiles($pingRtt)));
$pinging->disconnect();

$client->disconnect();
await(delay(0.2));
Loop::stop();
