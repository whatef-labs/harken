<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\HealthStore;
use Whatef\Harken\Laravel\RuntimeBuilder;
use Whatef\Harken\Observability\HttpEndpoint;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Observability\MetricsLog;
use Whatef\Harken\Observability\MetricsRegistry;
use Whatef\Harken\Observability\NullMetrics;
use Whatef\Harken\Observability\PrometheusFormat;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Runtime\Leadership\LeadershipState;
use Whatef\Harken\Runtime\Outbox\RedisStreamsOutbox;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Tests\Fixtures\RecordingHandler;
use Whatef\Harken\Tests\Support\FakeBroker;
use Whatef\Harken\Tests\Support\FakeLoop;

beforeEach(function () {
    RecordingHandler::$messages = [];
    config()->set('harken.connections.default.leader.lock', 'none');
    config()->set('harken.outbox.enabled', false);
    config()->set('harken.health.cache_store', 'array');
    config()->set('cache.default', 'array');
});

/**
 * @return array{Runtime, FakeBroker, FakeLoop}
 */
function built_runtime(): array
{
    $loop = new FakeLoop;
    $broker = new FakeBroker(new Mqtt5Codec);
    $runtime = (new RuntimeBuilder(app(), config(), app(HarkenManager::class)))
        ->build('default', config('harken.connections.default'), $loop, $broker->transport);

    return [$runtime, $broker, $loop];
}

it('subscribes shared and broadcast routes on the main connection and leader routes on the leader connection', function () {
    [$runtime, $broker] = built_runtime();
    $runtime->start();

    $main = $broker->use(0)->accept();
    $mainSubscribe = $broker->grant();
    $leader = $broker->use(1)->accept(new ConnAck(sessionPresent: true));
    $leaderSubscribe = $broker->grant();

    expect($main->clientId)->toStartWith('laravel-harken-')
        ->and($main->cleanSession)->toBeTrue()
        ->and($mainSubscribe->filters)->toBe(['$share/ingest/sensors/+/report' => 1])
        ->and($leader->clientId)->toBe('laravel-harken-leader')
        ->and($leader->cleanSession)->toBeFalse()
        ->and($leader->sessionExpiryInterval)->toBe(300)
        ->and($leaderSubscribe->filters)->toBe(['sensors/+/temperature' => 1, 'sensors/+/broken' => 0])
        ->and($runtime->leadership?->state())->toBe(LeadershipState::Leading);
});

it('dispatches leader messages to their handlers from the container', function () {
    [$runtime, $broker] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();
    $broker->use(1)->accept();
    $broker->grant();

    $broker->send(new Publish('sensors/9/temperature', '21.5', 1, packetId: 3));

    expect(RecordingHandler::$messages)->toHaveCount(1)
        ->and(RecordingHandler::$messages[0]->param('id'))->toBe('9')
        ->and($broker->received())->toEqual([new PubAck(3)]);
});

it('publishes from inside the runtime on its own connection', function () {
    [$runtime, $broker] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();

    Harken::publish('cmd/door/1', 'open', 1);
    Harken::urgent()->publish('cmd/door/2', 'open');

    expect(array_map(fn (Publish $p) => $p->topic, $broker->received()))->toBe(['cmd/door/1', 'cmd/door/2']);
});

it('runs no election when every route is shared or broadcast', function () {
    config()->set('harken.routes', __DIR__.'/missing.php');
    app()->forgetInstance(HarkenManager::class);
    Harken::topic('sensors/{id}/status', fn () => null)->broadcast();

    [$runtime] = built_runtime();

    expect($runtime->leadership)->toBeNull();
});

it('builds the outbox on the configured Redis connection with its key prefix', function () {
    config()->set('harken.outbox.enabled', true);
    config()->set('database.redis.options.prefix', 'app_');

    [$runtime] = built_runtime();

    expect($runtime->outbox)->toBeInstanceOf(RedisStreamsOutbox::class)
        ->and($runtime->outbox->streams)->toBe(['app_harken:outbox:urgent', 'app_harken:outbox:normal'])
        ->and($runtime->outbox->consumer)->toBe($runtime->connection->options->clientId);
});

it('writes its heartbeat for harken:status, which fails once it goes stale', function () {
    [$runtime, $broker, $loop] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();
    $loop->advance(5.0); // the next heartbeat, now connected

    $this->artisan('harken:status')
        ->expectsOutputToContain('connected')
        ->assertSuccessful();

    $status = Cache::store('array')->get(Cache::store('array')->get(HealthStore::INDEX)[0]);
    $status['heartbeat_at'] = microtime(true) - 120;
    (new HealthStore(Cache::store('array'), 30))->write($status);

    $this->artisan('harken:status')
        ->expectsOutputToContain('no heartbeat for')
        ->assertFailed();
});

it('reports no runtime on this host', function () {
    $this->artisan('harken:status')
        ->expectsOutputToContain('No Harken runtime is running on')
        ->assertFailed();
});

it('stops when harken:restart is run after it started', function () {
    [$runtime, $broker, $loop] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();

    $this->artisan('harken:restart');
    $loop->advance(5.0);

    expect($runtime->state()->value)->not->toBe('running');
});

it('ignores the stale heartbeat of a process its host has replaced', function () {
    $health = new HealthStore(Cache::store('array'), 30);
    $now = microtime(true);
    $old = ['host' => 'web-1', 'connection' => 'default', 'pid' => 7, 'state' => 'running', 'started_at' => $now - 600, 'heartbeat_at' => $now - 300, 'mqtt' => ['state' => 'connected']];
    $new = ['host' => 'web-1', 'connection' => 'default', 'pid' => 8, 'state' => 'running', 'started_at' => $now - 10, 'heartbeat_at' => $now - 1, 'mqtt' => ['state' => 'connected']];
    $dead = ['host' => 'web-2', 'connection' => 'default', 'pid' => 9, 'state' => 'running', 'started_at' => $now - 600, 'heartbeat_at' => $now - 300, 'mqtt' => ['state' => 'connected']];
    $health->write($old);
    $health->write($new);
    $health->write($dead);

    $statuses = $health->all($now);

    expect(array_column($statuses, 'pid'))->toBe([8, 9])
        ->and($health->problem($statuses[0], $now))->toBeNull()
        ->and($health->problem($statuses[1], $now))->toStartWith('no heartbeat for')
        ->and(Cache::store('array')->get(HealthStore::INDEX))->toHaveCount(2);
});

it('runs leader timers once this replica leads, and broadcast timers regardless', function () {
    config()->set('harken.routes', __DIR__.'/missing.php');
    app()->forgetInstance(HarkenManager::class);
    $runs = ['leader' => 0, 'broadcast' => 0];
    Harken::every(1, function () use (&$runs) {
        $runs['leader']++;
    });
    Harken::every(1, function () use (&$runs) {
        $runs['broadcast']++;
    })->broadcast();

    [$runtime, $broker, $loop] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $loop->advance(3.0);

    expect($runtime->leadership?->isLeader())->toBeTrue()
        ->and($runtime->leadership?->connection())->toBeNull()
        ->and($runs)->toBe(['leader' => 3, 'broadcast' => 3]);

    $runtime->leadership?->stop();
    $loop->advance(3.0);

    expect($runs)->toBe(['leader' => 3, 'broadcast' => 6]);
});

it('picks the metrics driver from the configuration', function (string $driver, string $class) {
    config()->set('harken.metrics.driver', $driver);
    app()->forgetInstance(Metrics::class);

    expect(app(Metrics::class))->toBeInstanceOf($class);
})->with([
    ['null', NullMetrics::class],
    ['prometheus', MetricsRegistry::class],
    ['log', MetricsRegistry::class],
]);

it('rejects an unknown metrics driver', function () {
    config()->set('harken.metrics.driver', 'statsd');
    app()->forgetInstance(Metrics::class);

    app(Metrics::class);
})->throws(InvalidArgumentException::class, 'Unknown Harken metrics driver [statsd]');

it('serves /metrics and /health with the prometheus driver, and logs metrics with the log driver', function () {
    config()->set('harken.metrics.driver', 'prometheus');
    config()->set('harken.http.port', 0);
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();

    $endpoints = array_values(array_filter($runtime->sources, fn ($s) => $s instanceof HttpEndpoint));

    expect($endpoints)->toHaveCount(1)
        ->and((fn () => $this->metrics)->call($endpoints[0]))->toBeInstanceOf(MetricsRegistry::class);

    config()->set('harken.metrics.driver', 'log');
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();

    expect(array_map(fn ($s) => $s::class, $runtime->sources))->toBe([MetricsLog::class]);

    config()->set('harken.metrics.driver', 'null');
    config()->set('harken.http.enabled', true);
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();

    expect(array_map(fn ($s) => $s::class, $runtime->sources))->toBe([HttpEndpoint::class])
        ->and((fn () => $this->metrics)->call($runtime->sources[0]))->toBeNull();
});

it('counts received and published messages for the metrics', function () {
    config()->set('harken.metrics.driver', 'log'); // same registry, no listening socket
    app()->forgetInstance(Metrics::class);
    [$runtime, $broker, $loop] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();
    $broker->use(1)->accept();
    $broker->grant();
    $broker->send(new Publish('sensors/1/temperature', 'x'), new Publish('sensors/2/temperature', 'y'));
    $runtime->connection->publish('cmd/1', 'x');
    $loop->advance(5.0);

    $text = PrometheusFormat::render(app(MetricsRegistry::class));

    expect($text)->toContain('harken_messages_received_total{connection="leader"} 2')
        ->toContain('harken_messages_published_total{connection="main"} 1')
        ->toContain('harken_leader 1')
        ->toContain('harken_connected{connection="main"} 1')
        ->toContain('# TYPE harken_start_time_seconds gauge');
});
