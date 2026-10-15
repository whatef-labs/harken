<?php

declare(strict_types=1);

/*
 * Spike 02 — MQTT 3.1.1 clients and EMQX shared subscriptions ($share/g/topic).
 *
 *   php spikes/02-shared-subscription.php
 *
 * Env: SPIKE_MQTT_HOST (127.0.0.1), SPIKE_MQTT_PORT (1883),
 *      SPIKE_MQTT_USERNAME, SPIKE_MQTT_PASSWORD
 */

use React\EventLoop\Loop;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Transport\Endpoint;

use function React\Async\await;
use function React\Promise\all;
use function React\Promise\Timer\sleep as delay;

require __DIR__.'/support.php';
require __DIR__.'/MiniClient.php';

$endpoint = new Endpoint(getenv('SPIKE_MQTT_HOST') ?: '127.0.0.1', (int) (getenv('SPIKE_MQTT_PORT') ?: 1883));
$user = getenv('SPIKE_MQTT_USERNAME') ?: null;
$pass = getenv('SPIKE_MQTT_PASSWORD') ?: null;
$run = bin2hex(random_bytes(3));
$connect = static fn (string $id) => MiniClient::connect($endpoint, "spike02-{$run}-{$id}", true, 60, $user, $pass);

spike_line('broker', "{$endpoint->host}:{$endpoint->port}");
spike_line('run id', $run);

/**
 * @param  array<string, list<string>>  $seen
 */
$record = static function (MiniClient $client, string $name, array &$seen): void {
    $seen[$name] = [];
    $client->onMessage = function (Publish $p) use (&$seen, $name) {
        $seen[$name][] = $p->payload;
    };
};

// ------------------------------------------------- E1 distribution
echo "\nE1  two members of \$share/g/…, one plain subscriber, 1000 QoS 1 messages\n";
$filter = "spike/{$run}/share/+";
$seen = [];
$a = await($connect('a'));
$b = await($connect('b'));
$plain = await($connect('plain'));
$publisher = await($connect('pub'));
$record($a, 'a', $seen);
$record($b, 'b', $seen);
$record($plain, 'plain', $seen);

spike_line('SUBACK for $share filter (member a)', await($a->subscribe(["\$share/g{$run}/{$filter}" => 1]))->returnCodes);
spike_line('SUBACK for $share filter (member b)', await($b->subscribe(["\$share/g{$run}/{$filter}" => 1]))->returnCodes);
await($plain->subscribe([$filter => 1]));

$batch = [];
for ($i = 0; $i < 1000; $i++) {
    $batch[] = $publisher->publish("spike/{$run}/share/".($i % 10), "m{$i}", 1);
}
await(all($batch));
await(delay(1.0));

$union = array_merge($seen['a'], $seen['b']);
spike_line('member a received', count($seen['a']));
spike_line('member b received', count($seen['b']));
spike_line('a + b unique / total', count(array_unique($union)).' / '.count($union));
spike_line('delivered to both a and b', count(array_intersect($seen['a'], $seen['b'])));
spike_line('plain subscriber received', count($seen['plain']));

// ------------------------------------------------- E2 member crash with unacked messages
echo "\nE2  member a stops acknowledging and crashes; does b get a's messages?\n";
$seen = ['a' => [], 'b' => []];
$a->autoAck = false;
$record($a, 'a', $seen);
$record($b, 'b', $seen);
$batch = [];
for ($i = 0; $i < 200; $i++) {
    $batch[] = $publisher->publish("spike/{$run}/share/x", "n{$i}", 1);
}
await(all($batch));
await(delay(0.5));
$aUnacked = $seen['a'];
$a->kill();
await(delay(2.0));
$batch = [];
for ($i = 200; $i < 300; $i++) {
    $batch[] = $publisher->publish("spike/{$run}/share/x", "n{$i}", 1);
}
await(all($batch));
await(delay(1.0));

$covered = array_unique(array_merge($seen['a'], $seen['b']));
spike_line('a received (never acked) before crash', count($aUnacked));
spike_line('b received in total (of 300)', count($seen['b']));
spike_line("a's unacked messages later delivered to b", count(array_intersect($aUnacked, $seen['b'])));
spike_line('messages seen by nobody', 300 - count($covered));

foreach ([$b, $plain, $publisher] as $c) {
    $c->disconnect();
}
await(delay(0.2));
Loop::stop();
