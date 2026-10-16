<?php

declare(strict_types=1);

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Connection\ConnectionRefused;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Runtime\Routing\Dispatcher;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Routing\Router;
use Whatef\Harken\Runtime\Runtime;
use Whatef\Harken\Runtime\RuntimeObserver;
use Whatef\Harken\Runtime\RuntimeOptions;
use Whatef\Harken\Runtime\RuntimeState;
use Whatef\Harken\Runtime\Shutdown\ShutdownSequence;
use Whatef\Harken\Runtime\Timers\Scheduler;
use Whatef\Harken\Tests\Support\FakeBroker;
use Whatef\Harken\Tests\Support\FakeLoop;
use Whatef\Harken\Transport\Endpoint;

use function React\Promise\reject;
use function React\Promise\resolve;

final class RecordingRuntimeObserver implements RuntimeObserver
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array<string, mixed>> */
    public array $heartbeats = [];

    public bool $restart = false;

    public function started(Runtime $runtime): void
    {
        $this->events[] = 'started';
    }

    public function error(Throwable $error): void
    {
        $this->events[] = "error: {$error->getMessage()}";
    }

    public function failed(Throwable $error): void
    {
        $this->events[] = "failed: {$error->getMessage()}";
    }

    public function stopping(string $reason): void
    {
        $this->events[] = "stopping: {$reason}";
    }

    public function shutdownStepAbandoned(string $step, ?Throwable $error): void
    {
        $this->events[] = "abandoned {$step}".($error === null ? '' : ": {$error->getMessage()}");
    }

    public function stopped(string $reason, int $exitCode): void
    {
        $this->events[] = "stopped: {$reason} ({$exitCode})";
    }

    public function heartbeat(array $status): void
    {
        $this->heartbeats[] = $status;
    }

    public function restartRequested(float $startedAt): bool
    {
        return $this->restart;
    }
}

final class CallingHandlerInvoker implements HandlerInvoker
{
    public function invoke(Route $route, Message $message): void
    {
        ($route->getHandler())($message);
    }
}

final class SilentDispatchObserver implements DispatchObserver
{
    public function unmatched(Message $message): void {}

    public function handled(Route $route, Message $message, float $milliseconds): void {}

    public function slow(Route $route, Message $message, float $milliseconds): void {}

    public function failed(Route $route, Message $message, Throwable $exception, float $milliseconds): void {}
}

/**
 * @return array{Runtime, FakeBroker, FakeLoop, RecordingRuntimeObserver, Scheduler}
 */
function runtime(array $options = [], ?Closure $handler = null): array
{
    $loop = new FakeLoop;
    $codec = new Mqtt5Codec;
    $broker = new FakeBroker($codec);
    $connection = new Connection(
        new ConnectionOptions('default', new Endpoint('broker.test'), 'harken-test', new ExponentialBackoff(jitter: 0.0), keepAlive: 0),
        $broker->transport,
        $codec,
        $loop,
    );
    $router = new Router;
    $router->add((new Route('devices/{id}/up', $handler ?? fn () => null))->qos(1)->shared('g'));
    $connection->subscribe($router->subscriptions());
    $observer = new RecordingRuntimeObserver;
    $scheduler = new Scheduler;

    $runtime = new Runtime(
        $loop,
        $connection,
        new Dispatcher($router, new CallingHandlerInvoker, new SilentDispatchObserver),
        $scheduler,
        $observer,
        new RuntimeOptions(...$options + ['graceSeconds' => 2.0, 'handleSignals' => false]),
    );

    return [$runtime, $broker, $loop, $observer, $scheduler];
}

it('starts, subscribes and dispatches messages', function () {
    $handled = [];
    [$runtime, $broker, , $observer] = runtime(handler: function (Message $message) use (&$handled) {
        $handled[] = $message->param('id');
    });

    $runtime->start();
    $broker->accept();
    $subscribe = $broker->grant();
    $broker->send(new Publish('devices/7/up', 'x', 1, packetId: 1));

    expect($subscribe->filters)->toBe(['$share/g/devices/+/up' => 1])
        ->and($handled)->toBe(['7'])
        ->and($broker->received())->toEqual([new PubAck(1)])
        ->and($runtime->state())->toBe(RuntimeState::Running)
        ->and($observer->events)->toBe(['started']);
});

it('reports a status heartbeat at start and every interval', function () {
    [$runtime, $broker, $loop, $observer] = runtime(['statusInterval' => 5.0]);
    $runtime->start();
    $broker->accept();
    $broker->grant();
    $broker->send(new Publish('devices/1/up', 'x'));
    $loop->advance(5.0);

    $status = end($observer->heartbeats);

    expect($observer->heartbeats)->toHaveCount(3) // at start, once connected, after 5 s
        ->and($status['state'])->toBe('running')
        ->and($status['client_id'])->toBe('harken-test')
        ->and($status['protocol'])->toBe('5.0')
        ->and($status['mqtt']['state'])->toBe('connected')
        ->and($status['mqtt']['received'])->toBe(1)
        ->and($status['leader'])->toBeNull()
        ->and($status['outbox'])->toBeNull()
        ->and($status)->toHaveKeys(['pid', 'host', 'heartbeat_at', 'loop_lag_ms', 'memory_mb']);
});

it('stops gracefully: holds inbound, waits for PUBACKs, then disconnects', function () {
    $handled = 0;
    [$runtime, $broker, $loop, $observer] = runtime(handler: function () use (&$handled) {
        $handled++;
    });
    $runtime->start();
    $broker->accept();
    $broker->grant();
    $runtime->connection->publish('cmd/1', 'x', 1);
    $sent = $broker->expect(Publish::class);

    $runtime->stop('SIGTERM');
    $broker->send(new Publish('devices/1/up', 'late', 1, packetId: 9));

    expect($runtime->state())->toBe(RuntimeState::Stopping)
        ->and($handled)->toBe(0)
        ->and($broker->received())->toBe([]);

    $peer = $broker->transport->peer();
    $broker->send(new PubAck($sent->packetId));

    expect((new Mqtt5Codec)->decoder()->push($peer->drain()))->toEqual([new Disconnect])
        ->and($runtime->state())->toBe(RuntimeState::Stopped)
        ->and($runtime->connection->state())->toBe(ConnectionState::Disconnected)
        ->and($observer->events)->toBe(['started', 'stopping: SIGTERM', 'stopped: SIGTERM (0)']);

    $loop->advance(10.0);
});

it('disconnects anyway when the grace period runs out', function () {
    [$runtime, $broker, $loop, $observer] = runtime();
    $runtime->start();
    $broker->accept();
    $broker->grant();
    $runtime->connection->publish('cmd/1', 'x', 1)->then(null, fn () => null); // never acknowledged

    $runtime->stop('restart');
    $loop->advance(2.1);

    expect($runtime->state())->toBe(RuntimeState::Stopped)
        ->and($observer->events)->toContain('abandoned publishes', 'stopped: restart (0)');
});

it('stops when a restart is requested', function () {
    [$runtime, $broker, $loop, $observer] = runtime(['restartCheckInterval' => 1.0]);
    $runtime->start();
    $broker->accept();
    $broker->grant();

    $observer->restart = true;
    $loop->advance(1.0);

    expect($observer->events)->toContain('stopping: restart requested');
});

it('stops after the time limit', function () {
    [$runtime, $broker, $loop, $observer] = runtime(['maxSeconds' => 30.0]);
    $runtime->start();
    $broker->accept();
    $broker->grant();
    $loop->advance(30.0);

    expect($observer->events)->toContain('stopping: time limit reached');
});

it('stops with exit code 1 when the broker refuses the connection for good', function () {
    [$runtime, $broker, , $observer] = runtime();
    $runtime->start();
    $broker->accept(new ConnAck(ReasonCode::NotAuthorized));

    expect($runtime->failure())->toBeInstanceOf(ConnectionRefused::class)
        ->and($runtime->state())->toBe(RuntimeState::Stopped)
        ->and($observer->events)->toContain('failed: The broker refused the MQTT 5.0 connection: 0x87 not authorized.', 'stopped: failure (1)');
});

it('runs timers and reports failing ones without stopping', function () {
    [$runtime, $broker, $loop, $observer, $scheduler] = runtime();
    $ticks = 0;
    $scheduler->every(1, function () use (&$ticks) {
        $ticks++;
    });
    $scheduler->every(1, fn () => throw new RuntimeException('sweep failed'));
    $runtime->start();
    $loop->advance(2.0);

    expect($ticks)->toBe(2)
        ->and($observer->events)->toContain('error: sweep failed')
        ->and($runtime->state())->toBe(RuntimeState::Running);
});

it('runs shutdown steps in order and moves on from slow or failing ones', function () {
    $loop = new FakeLoop;
    $order = [];
    $abandoned = [];
    $slow = new Deferred;

    (new ShutdownSequence(1.0))->run($loop, [
        'first' => function () use (&$order): PromiseInterface {
            $order[] = 'first';

            return resolve(null);
        },
        'failing' => function () use (&$order): PromiseInterface {
            $order[] = 'failing';

            return reject(new RuntimeException('boom'));
        },
        'slow' => function () use (&$order, $slow): PromiseInterface {
            $order[] = 'slow';

            return $slow->promise();
        },
        'last' => function (float $left) use (&$order): PromiseInterface {
            $order[] = sprintf('last %.1f', $left);

            return resolve(null);
        },
    ], function (string $step, ?Throwable $error) use (&$abandoned) {
        $abandoned[] = $step.($error === null ? '' : ": {$error->getMessage()}");
    });

    expect($order)->toBe(['first', 'failing', 'slow']);

    $loop->advance(1.0);

    expect($order[3])->toStartWith('last')
        ->and($abandoned)->toBe(['failing: boom', 'slow']);
});
