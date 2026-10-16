<?php

declare(strict_types=1);

/*
 * MQTT 5 codec against a real broker and an independent MQTT 5 client.
 *
 *   HARKEN_TEST_BROKER_HOST=127.0.0.1 vendor/bin/pest --group=integration
 *
 * The mosquitto test also needs mosquitto_pub / mosquitto_sub on the PATH
 * (installed in docker/php).
 */

use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\Packet\SubAck;
use Whatef\Harken\Protocol\Packet\Subscribe;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Tests\Support\WireClient;

beforeEach(function () {
    if (! getenv('HARKEN_TEST_BROKER_HOST')) {
        $this->markTestSkipped('Set HARKEN_TEST_BROKER_HOST to run broker integration tests.');
    }
});

function interop_client_id(): string
{
    return 'harken-it-'.bin2hex(random_bytes(5));
}

function interop_topic(string $name): string
{
    static $run = null;
    $run ??= bin2hex(random_bytes(4));

    return "harken-it/{$run}/{$name}";
}

/**
 * @return array{WireClient, ConnAck}
 */
function interop_connect(?Connect $connect = null, bool $mqtt5 = true): array
{
    [$client, $connAck] = WireClient::connect(
        (string) getenv('HARKEN_TEST_BROKER_HOST'),
        (int) (getenv('HARKEN_TEST_BROKER_PORT') ?: 1883),
        $mqtt5 ? new Mqtt5Codec : new BinsoulCodec,
        $connect ?? new Connect(interop_client_id()),
    );

    expect($connAck->accepted())->toBeTrue();

    return [$client, $connAck];
}

function interop_subscribe(WireClient $client, string $filter, int $packetId = 1): void
{
    $client->send(new Subscribe($packetId, [$filter => 1]));

    expect($client->expect(SubAck::class)->failed())->toBeFalse();
}

/**
 * Acknowledges every QoS 1 PUBLISH that arrives in the window and returns them.
 *
 * @return list<Publish>
 */
function interop_receive(WireClient $client, float $seconds): array
{
    $messages = $client->collect(Publish::class, $seconds);

    foreach ($messages as $message) {
        if ($message->packetId !== null) {
            $client->send(new PubAck($message->packetId));
        }
    }

    return $messages;
}

it('connects with MQTT 5 and reads the limits the broker announces', function () {
    [$client, $connAck] = interop_connect(new Connect(
        interop_client_id(),
        receiveMaximum: 10,
        maximumPacketSize: 1048576,
        userProperties: ['app' => 'harken'],
    ));

    expect($connAck->reasonCode)->toBe(ReasonCode::Success)
        ->and($connAck->sessionPresent)->toBeFalse()
        ->and($connAck->retainAvailable ?? true)->toBeTrue()
        ->and($connAck->sharedSubscriptionAvailable ?? true)->toBeTrue()
        ->and($connAck->wildcardSubscriptionAvailable ?? true)->toBeTrue();

    $client->send(new Disconnect);
    expect($client->closesWithin(2.0))->toBeTrue();
});

it('round-trips message properties through the broker', function () {
    $topic = interop_topic('props');
    [$client] = interop_connect();
    interop_subscribe($client, $topic);

    $properties = new Properties(
        contentType: 'application/json',
        responseTopic: interop_topic('reply'),
        correlationData: "\x00\xffcorrelation",
        messageExpiryInterval: 300,
        payloadFormatIndicator: true,
        userProperties: ['trace' => 'abc', 'tag' => ['x', 'y']],
    );
    $client->send(new Publish($topic, '{"a":1}', 1, false, false, 2, $properties));

    expect($client->expect(PubAck::class))->toEqual(new PubAck(2));

    [$message] = interop_receive($client, 1.0);

    expect($message->payload)->toBe('{"a":1}')
        ->and($message->properties->contentType)->toBe('application/json')
        ->and($message->properties->responseTopic)->toBe(interop_topic('reply'))
        ->and($message->properties->correlationData)->toBe("\x00\xffcorrelation")
        ->and($message->properties->payloadFormatIndicator)->toBeTrue()
        ->and($message->properties->userProperties)->toBe(['trace' => 'abc', 'tag' => ['x', 'y']])
        ->and($message->properties->messageExpiryInterval)->toBeGreaterThan(290)->toBeLessThanOrEqual(300);
});

it('reports a QoS 1 publish nobody subscribes to with a reason code', function () {
    [$client] = interop_connect();

    $client->send(new Publish(interop_topic('nobody'), 'x', 1, false, false, 1));

    expect($client->expect(PubAck::class)->reasonCode)->toBe(ReasonCode::NoMatchingSubscribers);
});

it('delivers between MQTT 3.1.1 and 5 clients, without properties on the 3.1.1 side', function () {
    $topic = interop_topic('mixed');
    [$old] = interop_connect(mqtt5: false);
    [$new] = interop_connect();
    interop_subscribe($old, $topic);
    interop_subscribe($new, $topic);

    $new->send(new Publish($topic, 'from-5', 1, false, false, 2, new Properties(contentType: 'text/plain', userProperties: ['k' => 'v'])));
    $old->send(new Publish($topic, 'from-3', 1, false, false, 3));

    $onOld = interop_receive($old, 1.0);
    $onNew = interop_receive($new, 1.0);
    $byPayload = static fn (array $messages): array => array_column(array_map(static fn (Publish $m) => [$m->payload, $m], $messages), 1, 0);

    expect(array_keys($byPayload($onOld)))->toEqualCanonicalizing(['from-5', 'from-3'])
        ->and($byPayload($onOld)['from-5']->properties->isEmpty())->toBeTrue()
        ->and($byPayload($onNew)['from-5']->properties->contentType)->toBe('text/plain')
        ->and($byPayload($onNew)['from-5']->properties->userProperties)->toBe(['k' => 'v'])
        ->and($byPayload($onNew)['from-3']->properties->isEmpty())->toBeTrue();
});

it('sends no more unacknowledged messages than the Receive Maximum the client announces', function () {
    $topic = interop_topic('flow');
    [$subscriber] = interop_connect(new Connect(interop_client_id(), receiveMaximum: 5));
    [$publisher] = interop_connect();
    interop_subscribe($subscriber, $topic);

    for ($i = 1; $i <= 20; $i++) {
        $publisher->send(new Publish($topic, "m{$i}", 1, false, false, $i));
    }

    $held = $subscriber->collect(Publish::class, 1.5);
    expect($held)->toHaveCount(5);

    $received = $held;
    foreach ($held as $message) {
        $subscriber->send(new PubAck((int) $message->packetId));
    }

    $deadline = microtime(true) + 5.0;
    while (count($received) < 20 && microtime(true) < $deadline) {
        array_push($received, ...interop_receive($subscriber, 0.3));
    }

    expect(array_map(fn (Publish $m) => $m->payload, $received))->toBe(array_map(fn (int $i) => "m{$i}", range(1, 20)));
});

it('tells the old connection that its session was taken over', function () {
    $id = interop_client_id();
    [$first] = interop_connect(new Connect($id));
    [$second] = interop_connect(new Connect($id));

    expect($first->expect(Disconnect::class)->reasonCode)->toBe(ReasonCode::SessionTakenOver)
        ->and($first->closesWithin(2.0))->toBeTrue();

    $second->send(new Disconnect);
});

it('keeps a session with clean start off and a session expiry, and queues messages meanwhile', function () {
    $id = interop_client_id();
    $topic = interop_topic('persistent');
    $session = new Connect($id, cleanSession: false, sessionExpiryInterval: 60);

    [$client, $connAck] = interop_connect($session);
    expect($connAck->sessionPresent)->toBeFalse();
    interop_subscribe($client, $topic);
    $client->send(new Disconnect);
    expect($client->closesWithin(2.0))->toBeTrue();

    [$publisher] = interop_connect();
    foreach (['a', 'b', 'c'] as $i => $payload) {
        $publisher->send(new Publish($topic, $payload, 1, false, false, $i + 1));
        expect($publisher->expect(PubAck::class)->reasonCode->isError())->toBeFalse();
    }

    [$resumed, $connAck] = interop_connect($session);

    expect($connAck->sessionPresent)->toBeTrue()
        ->and(array_map(fn (Publish $m) => $m->payload, interop_receive($resumed, 1.0)))->toBe(['a', 'b', 'c']);

    // Expire the session now instead of after 60 s.
    $resumed->send(new Disconnect(sessionExpiryInterval: 0));
});

it('redelivers unacknowledged QoS 1 messages with DUP after a crash', function () {
    $id = interop_client_id();
    $topic = interop_topic('redeliver');
    $session = new Connect($id, cleanSession: false, sessionExpiryInterval: 60);

    [$client] = interop_connect($session);
    interop_subscribe($client, $topic);
    [$publisher] = interop_connect();
    $publisher->send(new Publish($topic, 'one', 1, false, false, 1));
    $publisher->send(new Publish($topic, 'two', 1, false, false, 2));

    $unacked = $client->collect(Publish::class, 1.0);
    expect($unacked)->toHaveCount(2);
    $client->drop();

    [$resumed, $connAck] = interop_connect($session);
    $again = interop_receive($resumed, 1.5);

    expect($connAck->sessionPresent)->toBeTrue()
        ->and(array_map(fn (Publish $m) => [$m->payload, $m->duplicate], $again))->toBe([['one', true], ['two', true]]);

    $resumed->send(new Disconnect(sessionExpiryInterval: 0));
});

it('spreads a shared subscription across MQTT 5 members', function () {
    $topic = interop_topic('shared');
    $filter = '$share/g'.bin2hex(random_bytes(3)).'/'.$topic;
    [$a] = interop_connect();
    [$b] = interop_connect();
    [$publisher] = interop_connect();
    interop_subscribe($a, $filter);
    interop_subscribe($b, $filter);

    for ($i = 1; $i <= 20; $i++) {
        $publisher->send(new Publish($topic, "m{$i}", 1, false, false, $i));
    }

    $onA = array_map(fn (Publish $m) => $m->payload, interop_receive($a, 1.5));
    $onB = array_map(fn (Publish $m) => $m->payload, interop_receive($b, 0.5));

    expect($onA)->not->toBeEmpty()
        ->and($onB)->not->toBeEmpty()
        ->and(array_intersect($onA, $onB))->toBe([])
        ->and(count($onA) + count($onB))->toBe(20);
});

it('exchanges message properties with mosquitto MQTT 5 clients', function () {
    if (trim((string) shell_exec('command -v mosquitto_pub mosquitto_sub')) === '') {
        $this->markTestSkipped('mosquitto_pub / mosquitto_sub are not installed.');
    }

    $host = escapeshellarg((string) getenv('HARKEN_TEST_BROKER_HOST'));
    $port = (int) (getenv('HARKEN_TEST_BROKER_PORT') ?: 1883);

    // mosquitto publishes, Harken decodes.
    $topic = interop_topic('from-mosquitto');
    [$client] = interop_connect();
    interop_subscribe($client, $topic);
    exec(sprintf(
        'mosquitto_pub -V 5 -h %s -p %d -q 1 -t %s -m %s -D publish content-type %s -D publish response-topic %s '
        .'-D publish correlation-data %s -D publish message-expiry-interval 120 -D publish payload-format-indicator 1 '
        .'-D publish user-property k v1 -D publish user-property k v2 -D publish user-property other x 2>&1',
        $host, $port, escapeshellarg($topic), escapeshellarg('hello'), escapeshellarg('text/plain'),
        escapeshellarg(interop_topic('reply')), escapeshellarg('corr-1'),
    ), $output, $status);
    expect($status)->toBe(0, implode("\n", $output));

    [$message] = interop_receive($client, 2.0);
    expect($message->payload)->toBe('hello')
        ->and($message->properties->contentType)->toBe('text/plain')
        ->and($message->properties->responseTopic)->toBe(interop_topic('reply'))
        ->and($message->properties->correlationData)->toBe('corr-1')
        ->and($message->properties->payloadFormatIndicator)->toBeTrue()
        ->and($message->properties->messageExpiryInterval)->toBeLessThanOrEqual(120)
        ->and($message->properties->userProperties)->toBe(['k' => ['v1', 'v2'], 'other' => 'x']);

    // Harken publishes a retained message, mosquitto decodes it.
    $topic = interop_topic('to-mosquitto');
    $client->send(new Publish($topic, 'world', 1, true, false, 5, new Properties(
        contentType: 'text/plain',
        responseTopic: interop_topic('reply'),
        correlationData: 'corr-2',
        payloadFormatIndicator: true,
        userProperties: ['k' => 'v'],
    )));
    expect($client->expect(PubAck::class)->reasonCode->isError())->toBeFalse();

    $line = trim((string) shell_exec(sprintf(
        'mosquitto_sub -V 5 -h %s -p %d -t %s -C 1 -W 5 -F %s 2>&1',
        $host, $port, escapeshellarg($topic), escapeshellarg('%C|%R|%D|%F|%P|%p'),
    )));
    $client->send(new Publish($topic, '', 1, true, false, 6));

    expect($line)->toBe('text/plain|'.interop_topic('reply').'|corr-2|1|k:v|world');
});
