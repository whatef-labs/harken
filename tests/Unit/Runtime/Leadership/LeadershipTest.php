<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Connection\Connection;
use Whatef\Harken\Runtime\Connection\ConnectionOptions;
use Whatef\Harken\Runtime\Connection\ConnectionState;
use Whatef\Harken\Runtime\Connection\SubscriptionRefused;
use Whatef\Harken\Runtime\Leadership\LeaderLock;
use Whatef\Harken\Runtime\Leadership\Leadership;
use Whatef\Harken\Runtime\Leadership\LeadershipObserver;
use Whatef\Harken\Runtime\Leadership\LeadershipState;
use Whatef\Harken\Runtime\Leadership\NullLeaderLock;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Reconnect\ExponentialBackoff;
use Whatef\Harken\Tests\Support\FakeBroker;
use Whatef\Harken\Tests\Support\FakeLeaderLock;
use Whatef\Harken\Tests\Support\FakeLoop;
use Whatef\Harken\Transport\Endpoint;

final class RecordingLeadershipObserver implements LeadershipObserver
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<Throwable> */
    public array $failures = [];

    public function acquired(int $term, ?float $vacantSeconds): void
    {
        $this->events[] = "acquired {$term} vacant=".($vacantSeconds === null ? 'null' : round($vacantSeconds, 2));
    }

    public function subscribed(int $term, float $takeoverSeconds, ?float $gapSeconds): void
    {
        $this->events[] = "subscribed {$term} gap=".($gapSeconds === null ? 'null' : round($gapSeconds, 2));
    }

    public function lost(int $term, string $reason): void
    {
        $this->events[] = "lost {$term}: {$reason}";
    }

    public function error(Throwable $error): void
    {
        $this->events[] = 'error: '.$error->getMessage();
    }

    public function failed(Throwable $error): void
    {
        $this->failures[] = $error;
    }
}

/**
 * One replica: its election, the broker its leader connection talks to, and
 * the messages that connection handled.
 */
final class Replica
{
    public readonly FakeBroker $broker;

    public readonly Leadership $leadership;

    public readonly RecordingLeadershipObserver $observer;

    /** @var list<string> */
    public array $handled = [];

    /** Seconds added to the loop clock, to simulate a paused process. */
    public float $skew = 0.0;

    public function __construct(FakeLoop $loop, LeaderLock $lock, float $lease = 5.0)
    {
        $codec = new Mqtt5Codec;
        $this->broker = new FakeBroker($codec);
        $this->observer = new RecordingLeadershipObserver;
        $options = new ConnectionOptions('default', new Endpoint('broker.test'), 'app-harken-leader', new ExponentialBackoff(jitter: 0.0), keepAlive: 0, cleanSession: false, sessionExpiry: 300);

        $this->leadership = new Leadership($loop, $lock, function () use ($options, $codec, $loop): Connection {
            $connection = new Connection($options, $this->broker->transport, $codec, $loop);
            $connection->subscribe(['devices/+/up' => 1]);
            $connection->onMessage(function (Message $message): void {
                $this->handled[] = $message->payload;
            });

            return $connection;
        }, $this->observer, $lease, 0.25, fn (): float => $loop->now() + $this->skew);
    }

    /**
     * Answers CONNECT and SUBSCRIBE of a new leader connection.
     */
    public function brokerAccepts(): void
    {
        $this->broker->accept(new ConnAck(sessionPresent: true));
        $this->broker->grant();
    }
}

it('leads alone: takes the lock, connects with the fixed id and subscribes', function () {
    $loop = new FakeLoop;
    $replica = new Replica($loop, new FakeLeaderLock($loop, FakeLeaderLock::store()));

    $replica->leadership->start();
    $connect = $replica->broker->accept(new ConnAck);
    $replica->broker->grant();

    expect($replica->leadership->isLeader())->toBeTrue()
        ->and($connect->clientId)->toBe('app-harken-leader')
        ->and($connect->cleanSession)->toBeFalse()
        ->and($connect->sessionExpiryInterval)->toBe(300)
        ->and($replica->observer->events)->toBe(['acquired 1 vacant=null', 'subscribed 1 gap=null']);
});

it('keeps the standby replica off the broker while the leader renews', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $a = new Replica($loop, new FakeLeaderLock($loop, $store));
    $b = new Replica($loop, new FakeLeaderLock($loop, $store));

    $a->leadership->start();
    $a->brokerAccepts();
    $b->leadership->start();
    $loop->advance(60.0);

    expect($a->leadership->isLeader())->toBeTrue()
        ->and($b->leadership->state())->toBe(LeadershipState::Standby)
        ->and($b->broker->connections())->toBe(0);
});

it('hands over within one retry interval when the leader stops gracefully', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $a = new Replica($loop, new FakeLeaderLock($loop, $store));
    $b = new Replica($loop, new FakeLeaderLock($loop, $store));
    $a->leadership->start();
    $a->brokerAccepts();
    $b->leadership->start();
    $loop->advance(10.0);
    $peer = $a->broker->transport->peer();

    $a->leadership->stop();

    expect((new Mqtt5Codec)->decoder()->push($peer->drain()))->toEqual([new Disconnect])
        ->and($store->holder)->toBeNull();

    $loop->advance(0.25);
    $b->brokerAccepts();

    expect($b->leadership->isLeader())->toBeTrue()
        ->and($b->observer->events)->toBe(['acquired 1 vacant=0.25', 'subscribed 1 gap=0.25']);
});

it('stops receiving when the lease runs out, before a standby replica can take over', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $lockA = new FakeLeaderLock($loop, $store);
    $a = new Replica($loop, $lockA);
    $b = new Replica($loop, new FakeLeaderLock($loop, $store));
    $a->leadership->start();
    $a->brokerAccepts();
    $b->leadership->start();
    $loop->advance(3.0); // renewed at 1.67 s: lease now ends at 6.67 s

    $lockA->unreachable = true; // A can no longer renew (stalled, partitioned)
    $loop->advance(3.6);

    expect($a->leadership->isLeader())->toBeTrue()->and($b->leadership->isLeader())->toBeFalse();

    $loop->advance(0.1); // 6.7 s: A's own count of the lease ran out first
    expect($a->leadership->isLeader())->toBeFalse()
        ->and($a->leadership->connection())->toBeNull()
        ->and($a->observer->events)->toContain('lost 1: the lease ran out before it could be renewed');

    $loop->advance(0.25);
    $b->brokerAccepts();
    expect($b->leadership->isLeader())->toBeTrue()
        ->and($b->observer->events[0])->toStartWith('acquired 1 vacant=5');
});

it('does not handle or acknowledge a message that arrives after the lease ran out', function () {
    $loop = new FakeLoop;
    $replica = new Replica($loop, new FakeLeaderLock($loop, FakeLeaderLock::store()));
    $replica->leadership->start();
    $replica->brokerAccepts();
    $connection = $replica->leadership->connection();
    $peer = $replica->broker->transport->peer();

    $replica->broker->send(new Publish('devices/1/up', 'in-lease', 1, packetId: 1));
    expect((new Mqtt5Codec)->decoder()->push($peer->drain()))->toEqual([new PubAck(1)]);

    // The process was paused past its lease: the clock moved on, but the
    // expiry timer has not run yet when the next message is read.
    $replica->skew = 10.0;
    $replica->broker->send(new Publish('devices/1/up', 'after-lease', 1, packetId: 2));

    expect($replica->handled)->toBe(['in-lease'])
        ->and($peer->drain())->toBe('')
        ->and($connection?->state())->toBe(ConnectionState::Disconnected)
        ->and($replica->leadership->isLeader())->toBeFalse()
        ->and($replica->observer->events)->toContain('lost 1: the lease ran out before it could be renewed');
});

it('steps down when a renewal finds the lock taken', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $replica = new Replica($loop, new FakeLeaderLock($loop, $store));
    $replica->leadership->start();
    $replica->brokerAccepts();

    $store->holder = 'someone-else';
    $loop->advance(1.7);

    expect($replica->leadership->isLeader())->toBeFalse()
        ->and($replica->observer->events)->toContain('lost 1: another replica holds the lock');
});

it('steps down and frees the lock when the broker hands the leader session to another client', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $replica = new Replica($loop, new FakeLeaderLock($loop, $store));
    $replica->leadership->start();
    $replica->brokerAccepts();

    $replica->broker->send(new Disconnect(ReasonCode::SessionTakenOver));

    expect($replica->leadership->state())->toBe(LeadershipState::Standby)
        ->and($store->holder)->toBeNull()
        ->and($replica->observer->failures)->toBe([])
        ->and($replica->observer->events)->toContain('lost 1: another client took over the leader session');

    $loop->advance(0.25);
    expect($replica->broker->connections())->toBe(2); // tries again
});

it('fails when two replicas run without a real lock', function () {
    $loop = new FakeLoop;
    $replica = new Replica($loop, new NullLeaderLock);
    $replica->leadership->start();
    $replica->brokerAccepts();

    $replica->broker->send(new Disconnect(ReasonCode::SessionTakenOver));

    expect($replica->observer->failures)->toHaveCount(1)
        ->and($replica->observer->failures[0]->getMessage())->toContain('With the "none" leader lock only one replica may run');
});

it('fails when the leader connection cannot subscribe, and frees the lock', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $replica = new Replica($loop, new FakeLeaderLock($loop, $store));
    $replica->leadership->start();
    $replica->broker->accept();
    $replica->broker->grant([ReasonCode::NotAuthorized]);

    expect($replica->observer->failures[0])->toBeInstanceOf(SubscriptionRefused::class)
        ->and($store->holder)->toBeNull();
});

it('retries after a lock error', function () {
    $loop = new FakeLoop;
    $lock = new FakeLeaderLock($loop, FakeLeaderLock::store());
    $lock->unreachable = true;
    $replica = new Replica($loop, $lock);
    $replica->leadership->start();

    expect($replica->observer->events)->toBe(['error: lock backend unreachable']);

    $lock->unreachable = false;
    $loop->advance(0.25);

    expect($replica->leadership->isLeader())->toBeTrue();
});

it('reports the gap since the previous leader was last seen', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $store->seenAt = 0.0;
    $loop->advance(3.0);
    $replica = new Replica($loop, new FakeLeaderLock($loop, $store));

    $replica->leadership->start();
    $loop->advance(0.5);
    $replica->brokerAccepts();

    expect($replica->observer->events)->toBe(['acquired 1 vacant=3', 'subscribed 1 gap=3.5']);
});

it('stops a standby replica without touching the lock', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $store->holder = 'leader';
    $store->expiresAt = 100.0;
    $lock = new FakeLeaderLock($loop, $store);
    $replica = new Replica($loop, $lock);
    $replica->leadership->start();
    $calls = $lock->calls;

    $replica->leadership->stop();
    $loop->advance(10.0);

    expect($lock->calls)->toBe($calls)
        ->and($store->holder)->toBe('leader')
        ->and($replica->leadership->state())->toBe(LeadershipState::Stopped);
});

it('runs leader-only work without a leader connection, and tells when terms start and end', function () {
    $loop = new FakeLoop;
    $store = FakeLeaderLock::store();
    $observer = new RecordingLeadershipObserver;
    $leadership = new Leadership($loop, new FakeLeaderLock($loop, $store), null, $observer, 5.0, 0.25, $loop->now(...));
    $terms = [];
    $leadership->onTerm(function (int $term) use (&$terms, $leadership) {
        $terms[] = "start {$term} ".($leadership->holdsLease() ? 'held' : 'not held');
    }, function (int $term) use (&$terms) {
        $terms[] = "end {$term}";
    });

    $leadership->start();
    $store->holder = 'someone-else';
    $loop->advance(1.7);
    $store->holder = null;
    $loop->advance(0.25);
    $leadership->stop();

    expect($terms)->toBe(['start 1 held', 'end 1', 'start 2 held', 'end 2'])
        ->and($leadership->connection())->toBeNull()
        ->and($leadership->holdsLease())->toBeFalse()
        ->and($observer->events[1])->toBe('subscribed 1 gap=null');
});
