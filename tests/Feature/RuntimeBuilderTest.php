<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Laravel\HarkenManager;
use Whatef\Harken\Laravel\HealthStore;
use Whatef\Harken\Laravel\MetricsConfig;
use Whatef\Harken\Laravel\Pulse\PulseReporter;
use Whatef\Harken\Laravel\RuntimeBuilder;
use Whatef\Harken\Observability\HttpEndpoint;
use Whatef\Harken\Observability\Metrics;
use Whatef\Harken\Observability\MetricsLog;
use Whatef\Harken\Observability\MetricsRegistry;
use Whatef\Harken\Observability\NullMetrics;
use Whatef\Harken\Observability\PrometheusFormat;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\ConnectionRefused;
use Whatef\Harken\Runtime\Leadership\LeadershipState;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Outbox\RedisStreamsOutbox;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Runtime\RuntimeState;
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
        ->and($runtime->outbox->consumer)->toBe($runtime->connection->options->clientId)
        ->and($runtime->outbox->pruneIdleMs)->toBe(3_600_000);

    config()->set('harken.outbox.prune_idle_consumers_ms', 0);

    expect(built_runtime()[0]->outbox->pruneIdleMs)->toBeNull();
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

/**
 * A logger that keeps the messages, bound where the runtime looks for one.
 */
function recording_logger(): AbstractLogger
{
    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = "{$level}: {$message}".(isset($context['exit_code']) ? " exit code {$context['exit_code']}" : '');
        }
    };
    app()->instance(LoggerInterface::class, $logger);

    return $logger;
}

it('stops with exit code 1, logging the cause once, when the broker refuses the connection for good', function () {
    $logger = recording_logger();
    [$runtime, $broker, $loop] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept(new ConnAck(ReasonCode::NotAuthorized));
    $loop->advance(1.0);

    expect($runtime->state())->toBe(RuntimeState::Stopped)
        ->and($runtime->failure())->toBeInstanceOf(ConnectionRefused::class)
        ->and(array_values(array_filter($logger->messages, fn (string $m) => str_contains($m, 'cannot continue'))))
        ->toBe(['error: Harken cannot continue: The broker refused the MQTT 5.0 connection: 0x87 not authorized.'])
        ->and($logger->messages)->toContain('info: Harken runtime stopped (failure). exit code 1');
});

it('stops with exit code 1, logging the cause once, when another client takes the leader session under the none lock', function () {
    $logger = recording_logger();
    [$runtime, $broker, $loop] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();
    $broker->use(1)->accept();
    $broker->grant();

    $broker->send(new Disconnect(ReasonCode::SessionTakenOver));
    $loop->advance(1.0);

    expect($runtime->state())->not->toBe(RuntimeState::Running)
        ->and($runtime->failure()?->getMessage())->toContain('With the "none" leader lock only one replica may run')
        ->and(array_filter($logger->messages, fn (string $m) => str_contains($m, 'cannot continue')))->toHaveCount(1);
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

it('picks the metrics driver from the configuration', function (mixed $driver, string $class) {
    config()->set('harken.metrics.driver', $driver);
    app()->forgetInstance(Metrics::class);

    expect(app(Metrics::class))->toBeInstanceOf($class);
})->with([
    ['null', NullMetrics::class],
    [null, NullMetrics::class],
    ['', NullMetrics::class],
    ['prometheus', MetricsRegistry::class],
    ['log', MetricsRegistry::class],
    ['pulse', MetricsRegistry::class],
    ['prometheus, log', MetricsRegistry::class],
    [['log', 'pulse'], MetricsRegistry::class],
]);

it('reads several metrics drivers, each once, from a comma-separated list or an array', function (mixed $driver, array $drivers) {
    config()->set('harken.metrics.driver', $driver);

    expect(MetricsConfig::drivers(config()))->toBe($drivers);
})->with([
    ['null', []],
    ['prometheus,log', ['prometheus', 'log']],
    [' log , prometheus ,log', ['log', 'prometheus']],
    ['pulse,', ['pulse']],
    [['prometheus', 'pulse'], ['prometheus', 'pulse']],
]);

it('rejects an unknown metrics driver, alone or in a list', function (string $driver) {
    config()->set('harken.metrics.driver', $driver);
    app()->forgetInstance(Metrics::class);

    app(Metrics::class);
})->with(['statsd', 'prometheus,statsd'])->throws(InvalidArgumentException::class, 'Unknown Harken metrics driver [statsd]');

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

    config()->set('harken.metrics.driver', 'pulse');
    config()->set('harken.metrics.pulse_interval', 30);
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();

    expect(array_map(fn ($s) => $s::class, $runtime->sources))->toBe([PulseReporter::class])
        ->and($runtime->sources[0]->intervalSeconds)->toBe(30.0)
        ->and($runtime->sources[0]->connection)->toBe('default');

    config()->set('harken.metrics.driver', 'null');
    config()->set('harken.http.enabled', true);
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();

    expect(array_map(fn ($s) => $s::class, $runtime->sources))->toBe([HttpEndpoint::class])
        ->and((fn () => $this->metrics)->call($runtime->sources[0]))->toBeNull();
});

it('runs several metrics drivers together on the same registry', function () {
    config()->set('harken.metrics.driver', 'prometheus,log,pulse');
    config()->set('harken.http.port', 0);
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();
    $registry = app(MetricsRegistry::class);

    expect(array_map(fn ($s) => $s::class, $runtime->sources))->toBe([MetricsLog::class, PulseReporter::class, HttpEndpoint::class])
        ->and(array_map(fn ($s) => (fn () => $this->metrics)->call($s), $runtime->sources))->each->toBe($registry);
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

    // Stopping counts what arrived since the last status, before the metrics reporters stop.
    $broker->send(new Publish('sensors/3/temperature', 'z'));
    $runtime->stop('test');

    expect(PrometheusFormat::render(app(MetricsRegistry::class)))->toContain('harken_messages_received_total{connection="leader"} 3');
});

it('brings the received counter up to date before the pulse reporter reads it', function () {
    config()->set('harken.metrics.driver', 'pulse');
    app()->forgetInstance(Metrics::class);
    [$runtime, $broker] = built_runtime();
    $runtime->start();
    $broker->use(0)->accept();
    $broker->grant();
    $broker->use(1)->accept();
    $broker->grant();
    $broker->send(new Publish('sensors/1/temperature', 'x'), new Publish('sensors/2/temperature', 'y'));

    // No heartbeat since the messages arrived: only the reporter's status call counts them.
    expect(PrometheusFormat::render(app(MetricsRegistry::class)))->toContain('harken_messages_received_total{connection="leader"} 0');

    (fn () => ($this->status)())->call($runtime->sources[0]);

    expect(PrometheusFormat::render(app(MetricsRegistry::class)))->toContain('harken_messages_received_total{connection="leader"} 2');
});

it('starts every series at zero so Prometheus sees the first event', function () {
    config()->set('harken.metrics.driver', 'log');
    config()->set('harken.outbox.enabled', true);
    app()->forgetInstance(Metrics::class);
    built_runtime();

    expect(PrometheusFormat::render(app(MetricsRegistry::class)))
        ->toContain('harken_leader_takeovers_total 0')
        ->toContain('harken_handlers_failed_total{route="sensors/{id}/broken"} 0')
        ->toContain('harken_reconnects_total{connection="leader"} 0')
        ->toMatch('/harken_outbox_dropped_total{stream="[^"]*harken:outbox:urgent"} 0/')
        ->toMatch('/harken_outbox_trimmed_total{stream="[^"]*harken:outbox:normal"} 0/')
        ->toContain('harken_leader_gap_seconds_bucket{le="+Inf"} 0')
        ->toContain('harken_leader 0');
});

it('counts outbox entries that expired or were trimmed by max_length in the status and the metrics', function () {
    config()->set('harken.metrics.driver', 'log');
    config()->set('harken.outbox.enabled', true);
    app()->forgetInstance(Metrics::class);
    [$runtime] = built_runtime();
    $observer = (fn () => $this->observer)->call($runtime->outbox);

    $observer->expired('harken:outbox:normal', new OutboxMessage('cmd/late', ''));
    $observer->trimmed('harken:outbox:normal', 7);

    expect($runtime->status()['outbox'])->toMatchArray(['expired' => 1, 'trimmed' => 7])
        ->and(PrometheusFormat::render(app(MetricsRegistry::class)))
        ->toContain('harken_outbox_expired_total{stream="harken:outbox:normal"} 1')
        ->toContain('harken_outbox_trimmed_total{stream="harken:outbox:normal"} 7');
});
