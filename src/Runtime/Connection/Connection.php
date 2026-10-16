<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use Closure;
use InvalidArgumentException;
use LogicException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use ReflectionClass;
use SplQueue;
use Throwable;
use Whatef\Harken\Protocol\Codec;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Protocol\Packet\PingReq;
use Whatef\Harken\Protocol\Packet\PingResp;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\Packet\SubAck;
use Whatef\Harken\Protocol\Packet\Subscribe;
use Whatef\Harken\Protocol\Packet\UnsubAck;
use Whatef\Harken\Protocol\PacketIdPool;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\ProtocolVersion;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Runtime\Keepalive;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Runtime\Topic;
use Whatef\Harken\Transport\Transport;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * One long-lived MQTT session: connect, subscribe, publish, keepalive, and
 * reconnect with resubscription. Speaks MQTT 3.1.1 or 5 through its codec.
 *
 * - Inbound QoS 1 messages are acknowledged after the message listener
 *   returns, so a crash during the handler leaves the message unacknowledged
 *   for the broker to redeliver.
 * - QoS 1 publishes resolve on PUBACK. Publishes made while reconnecting wait
 *   in a bounded queue; unacknowledged ones are sent again (DUP) after a
 *   reconnect. At most the broker's Receive Maximum are in flight at once.
 *   Urgent publishes wait in a queue of their own that is served first.
 * - Transient failures reconnect with backoff. A permanent refusal, a refused
 *   subscription, an MQTT 5 "session taken over" or disconnect() end the
 *   connection for good and notify the close listeners.
 */
final class Connection implements Publisher
{
    private ConnectionState $state = ConnectionState::Disconnected;

    /** @var list<Closure(ConnectionState, ConnectionState): void> */
    private array $stateListeners = [];

    /** @var list<Closure(Throwable): void> */
    private array $errorListeners = [];

    /** @var list<Closure(?Throwable): void> */
    private array $closeListeners = [];

    /** @var (Closure(Message): void)|null */
    private ?Closure $onMessage = null;

    /** @var (Closure(): bool)|null */
    private ?Closure $guard = null;

    /** @var (Closure(string, Packet): void)|null */
    private ?Closure $tracer = null;

    /** @var array<string, int> */
    private array $subscriptions = [];

    private ?ConnectionInterface $socket = null;

    private ?Decoder $decoder = null;

    private ?Keepalive $keepalive = null;

    private ?Throwable $socketError = null;

    private readonly PacketIdPool $ids;

    private BrokerLimits $limits;

    /** @var array<int, OutboundPublish> QoS 1 publishes awaiting PUBACK, by packet id. */
    private array $inFlight = [];

    /** @var SplQueue<OutboundPublish> */
    private SplQueue $queue;

    /** @var SplQueue<OutboundPublish> */
    private SplQueue $urgentQueue;

    /** @var array<int, Deferred<SubAck>> */
    private array $subAcks = [];

    /** @var list<Deferred<null>> */
    private array $idleWaiters = [];

    /** @var Deferred<null>|null */
    private ?Deferred $started = null;

    private bool $startSettled = false;

    private bool $finished = false;

    private bool $holdingInbound = false;

    private ?Throwable $lastError = null;

    private int $receivedCount = 0;

    private int $publishedCount = 0;

    private ?TimerInterface $retryTimer = null;

    private ?TimerInterface $responseTimer = null;

    private int $attempt = 0;

    private int $failedAttempts = 0;

    private int $connects = 0;

    private bool $sessionPresent = false;

    private ?float $connectedAt = null;

    private ?float $disconnectedAt = null;

    public function __construct(
        public readonly ConnectionOptions $options,
        private readonly Transport $transport,
        private readonly Codec $codec,
        private readonly LoopInterface $loop,
    ) {
        if ($codec->version() !== $options->protocol) {
            throw new InvalidArgumentException(sprintf(
                'The codec speaks MQTT %s but the connection is configured for MQTT %s.',
                $codec->version()->value,
                $options->protocol->value,
            ));
        }

        $this->ids = new PacketIdPool;
        $this->limits = new BrokerLimits;
        $this->queue = new SplQueue;
        $this->urgentQueue = new SplQueue;
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    /**
     * @param  callable(ConnectionState $from, ConnectionState $to): void  $listener
     */
    public function onStateChange(callable $listener): void
    {
        $this->stateListeners[] = $listener(...);
    }

    /**
     * Inbound messages. A QoS 1 message is acknowledged once this returns.
     *
     * @param  callable(Message): void  $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->onMessage = $handler(...);
    }

    /**
     * Problems the connection recovers from by itself (a failed attempt, a
     * lost connection), reported for logs and metrics.
     *
     * @param  callable(Throwable): void  $listener
     */
    public function onError(callable $listener): void
    {
        $this->errorListeners[] = $listener(...);
    }

    /**
     * Called once when the connection ends for good: null after disconnect(),
     * otherwise the reason it could not go on.
     *
     * @param  callable(?Throwable): void  $listener
     */
    public function onClose(callable $listener): void
    {
        $this->closeListeners[] = $listener(...);
    }

    /**
     * A check that must pass before every connection attempt and before each
     * inbound message is handled. When it fails, the connection closes
     * without acknowledging the message and does not reconnect. The leader
     * connection uses it to stop as soon as its lease is no longer valid.
     *
     * @param  callable(): bool  $guard
     */
    public function guard(callable $guard): void
    {
        $this->guard = $guard(...);
    }

    /**
     * Stops handling inbound messages while the connection stays up for
     * publishing, for example while a stopping runtime waits for its last
     * PUBACKs. Held QoS 1 messages are not acknowledged, so the broker
     * delivers them again (to the session, or to another shared
     * subscription member) once this connection closes.
     */
    public function holdInbound(): void
    {
        $this->holdingInbound = true;
    }

    /**
     * Every packet sent ("out") and received ("in"), for --debug output.
     *
     * @param  callable(string $direction, Packet $packet): void  $tracer
     */
    public function trace(callable $tracer): void
    {
        $this->tracer = $tracer(...);
    }

    /**
     * Filters (with QoS) to subscribe to after every successful connect,
     * including reconnects with a clean session. Call before connect().
     *
     * @param  array<string, int>  $filters
     */
    public function subscribe(array $filters): void
    {
        if ($this->state !== ConnectionState::Disconnected || $this->started !== null) {
            throw new LogicException('Subscriptions must be set before the connection starts.');
        }

        $this->subscriptions = $filters + $this->subscriptions;
    }

    /**
     * @return array<string, int>
     */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }

    /**
     * Resolves once connected and subscribed for the first time. Transient
     * failures are retried with backoff; the promise rejects on a permanent
     * refusal, a refused subscription, or when the backoff gives up.
     *
     * @return PromiseInterface<null>
     */
    public function connect(): PromiseInterface
    {
        if ($this->started !== null) {
            return $this->started->promise();
        }

        $started = $this->started = new Deferred;
        $this->attempt();

        return $started->promise();
    }

    /**
     * QoS 0 resolves once written to the socket, QoS 1 once the PUBACK
     * arrives. On MQTT 5 a PUBACK with an error reason code rejects with
     * PublishRejected.
     *
     * @return PromiseInterface<null>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null, bool $urgent = false): PromiseInterface
    {
        try {
            Topic::assertPublishable($topic, $qos);
        } catch (InvalidArgumentException $e) {
            return reject($e);
        }

        if ($this->state === ConnectionState::Stopping || $this->state === ConnectionState::Disconnected) {
            return reject(ConnectionClosed::notConnected());
        }

        if ($this->queuedCount() >= $this->options->maxQueued) {
            return reject(PublishRejected::queueFull($topic, $this->options->maxQueued));
        }

        $outbound = new OutboundPublish(new Publish($topic, $payload, $qos, $retain, properties: $properties ?? new Properties));
        ($urgent ? $this->urgentQueue : $this->queue)->enqueue($outbound);
        $this->flush();

        return $outbound->deferred->promise();
    }

    /**
     * Resolves when no publish is waiting or in flight, for example before a
     * graceful disconnect.
     *
     * @return PromiseInterface<null>
     */
    public function whenIdle(): PromiseInterface
    {
        if ($this->inFlight === [] && $this->queuedCount() === 0) {
            return resolve(null);
        }

        $deferred = new Deferred;
        $this->idleWaiters[] = $deferred;

        return $deferred->promise();
    }

    /**
     * Sends DISCONNECT (normal disconnection) and closes the socket. Stops
     * reconnecting and rejects publishes that are still waiting. A session
     * with a session expiry survives this for the next connection with the
     * same client id.
     *
     * @return PromiseInterface<null>
     */
    public function disconnect(): PromiseInterface
    {
        if ($this->finished || $this->started === null) {
            return resolve(null);
        }

        $socket = $this->socket;

        if ($this->state === ConnectionState::Connected && $socket !== null) {
            $this->write(new Disconnect);
            $this->socket = null;
            $socket->end();
        }

        $this->finish(null);

        return resolve(null);
    }

    /**
     * Closes the socket without DISCONNECT and stops, as if the process had
     * died. The broker keeps the session and does not acknowledge anything
     * on the client's behalf.
     */
    public function abort(?Throwable $reason = null): void
    {
        $this->finish($reason ?? ConnectionClosed::stopped());
    }

    public function limits(): BrokerLimits
    {
        return $this->limits;
    }

    public function sessionPresent(): bool
    {
        return $this->sessionPresent;
    }

    /**
     * Unix time of the last successful connect, or null.
     */
    public function connectedAt(): ?float
    {
        return $this->connectedAt;
    }

    /**
     * Unix time the last connection was lost or closed, or null.
     */
    public function disconnectedAt(): ?float
    {
        return $this->disconnectedAt;
    }

    /**
     * Successful connects after the first one.
     */
    public function reconnects(): int
    {
        return max(0, $this->connects - 1);
    }

    /**
     * Inbound messages handed to the listener since the process started.
     */
    public function receivedCount(): int
    {
        return $this->receivedCount;
    }

    /**
     * Publishes written to the socket since the process started (QoS 1
     * counted once, not per resend).
     */
    public function publishedCount(): int
    {
        return $this->publishedCount;
    }

    /**
     * Why the connection last failed or ended, until it connects again.
     * State listeners can read it while handling the transition.
     */
    public function lastError(): ?Throwable
    {
        return $this->lastError;
    }

    public function inFlightCount(): int
    {
        return count($this->inFlight);
    }

    public function queuedCount(): int
    {
        return $this->queue->count() + $this->urgentQueue->count();
    }

    /**
     * @internal
     */
    public function transitionTo(ConnectionState $next): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw InvalidStateTransition::between($this->state, $next);
        }

        $previous = $this->state;
        $this->state = $next;

        foreach ($this->stateListeners as $listener) {
            $listener($previous, $next);
        }
    }

    private function attempt(): void
    {
        $this->retryTimer = null;

        if ($this->guard !== null && ! ($this->guard)()) {
            $this->finish(ConnectionClosed::guarded());

            return;
        }

        $attempt = ++$this->attempt;
        $this->socketError = null;
        $this->transitionTo(ConnectionState::Connecting);

        $this->transport->connect($this->options->endpoint)->then(
            function (ConnectionInterface $socket) use ($attempt): void {
                if ($attempt !== $this->attempt || $this->state !== ConnectionState::Connecting) {
                    $socket->close();

                    return;
                }

                $this->open($socket);
            },
            function (Throwable $e) use ($attempt): void {
                if ($attempt === $this->attempt && $this->state === ConnectionState::Connecting) {
                    $this->failed(ConnectionFailed::unreachable($this->options->endpoint->uri(), $e));
                }
            },
        );
    }

    private function open(ConnectionInterface $socket): void
    {
        $this->socket = $socket;
        $this->decoder = $this->codec->decoder();

        $socket->on('data', function (string $data) use ($socket): void {
            $this->receive($socket, $data);
        });
        $socket->on('error', function (Throwable $e) use ($socket): void {
            if ($socket === $this->socket) {
                $this->socketError = $e;
            }
        });
        $socket->on('close', function () use ($socket): void {
            if ($socket !== $this->socket) {
                return;
            }

            $this->socket = null;
            $this->failed($this->state === ConnectionState::Connecting
                ? ConnectionClosed::beforeConnAck($this->socketError)
                : ConnectionClosed::lost($this->socketError));
        });

        $timeout = $this->options->endpoint->connectTimeout;
        $this->responseTimer = $this->loop->addTimer($timeout, function () use ($timeout): void {
            $this->responseTimer = null;
            $this->failed(ConnectionFailed::noConnAck($timeout));
        });

        $this->write(new Connect(
            clientId: $this->options->clientId,
            keepAlive: $this->options->keepAlive,
            cleanSession: $this->options->cleanSession,
            username: $this->options->username,
            password: $this->options->password,
            sessionExpiryInterval: $this->options->sessionExpiry,
        ));
    }

    private function receive(ConnectionInterface $socket, string $data): void
    {
        if ($socket !== $this->socket || $this->decoder === null) {
            return;
        }

        try {
            $packets = $this->decoder->push($data);
        } catch (ProtocolException $e) {
            $this->protocolError($e->getMessage(), $e->reasonCode, $e);

            return;
        }

        $this->keepalive?->received();

        foreach ($packets as $packet) {
            if ($socket !== $this->socket) {
                return;
            }

            if ($this->tracer !== null) {
                ($this->tracer)('in', $packet);
            }

            $this->handle($packet);
        }
    }

    private function handle(Packet $packet): void
    {
        if ($this->state === ConnectionState::Connecting) {
            $packet instanceof ConnAck
                ? $this->connAck($packet)
                : $this->protocolError('expected CONNACK, got '.$this->name($packet).'.', ReasonCode::ProtocolError);

            return;
        }

        match (true) {
            $packet instanceof Publish => $this->inbound($packet),
            $packet instanceof PubAck => $this->pubAck($packet),
            $packet instanceof SubAck => $this->subAck($packet),
            $packet instanceof PingResp, $packet instanceof UnsubAck => null,
            $packet instanceof Disconnect => $this->disconnected($packet),
            default => $this->protocolError('unexpected '.$this->name($packet).'.', ReasonCode::ProtocolError),
        };
    }

    private function connAck(ConnAck $ack): void
    {
        $this->cancelResponseTimer();

        if (! $ack->accepted()) {
            $refused = ConnectionRefused::fromConnAck($ack, $this->options->protocol);
            $refused->isPermanent() ? $this->finish($refused) : $this->failed($refused);

            return;
        }

        $this->limits = BrokerLimits::fromConnAck($ack);
        $this->lastError = null;
        $this->sessionPresent = $ack->sessionPresent;
        $this->failedAttempts = 0;
        $this->connects++;
        $this->connectedAt = microtime(true);

        $keepAlive = $ack->serverKeepAlive ?? $this->options->keepAlive;
        $this->keepalive = new Keepalive(
            $this->loop,
            $keepAlive,
            fn () => $this->write(new PingReq),
            fn () => $this->failed(ConnectionClosed::keepAliveTimeout($keepAlive)),
        );
        $this->keepalive->start();

        $this->transitionTo(ConnectionState::Connected);

        if ($this->state !== ConnectionState::Connected) {
            return;
        }

        foreach ($this->inFlight as $outbound) {
            $outbound->packet = $outbound->packet->asDuplicate();
            $this->write($outbound->packet);
        }

        $this->flush();
        $this->subscribeAll();
    }

    private function subscribeAll(): void
    {
        if ($this->subscriptions === []) {
            $this->settleStart(null);

            return;
        }

        $shared = array_values(array_filter(array_keys($this->subscriptions), static fn (string $f): bool => str_starts_with($f, '$share/')));

        if ($shared !== [] && ! $this->limits->sharedSubscriptionAvailable) {
            $this->finish(SubscriptionRefused::unavailable($shared, ReasonCode::SharedSubscriptionsNotSupported, 'shared subscriptions'));

            return;
        }

        $wildcards = array_values(array_filter(array_keys($this->subscriptions), static fn (string $f): bool => strpbrk($f, '+#') !== false));

        if ($wildcards !== [] && ! $this->limits->wildcardSubscriptionAvailable) {
            $this->finish(SubscriptionRefused::unavailable($wildcards, ReasonCode::WildcardSubscriptionsNotSupported, 'wildcard subscriptions'));

            return;
        }

        $id = $this->ids->acquire();
        $deferred = new Deferred;
        $this->subAcks[$id] = $deferred;
        $filters = $this->subscriptions;
        $timeout = max(5.0, $this->options->endpoint->connectTimeout);
        $this->responseTimer = $this->loop->addTimer($timeout, function () use ($timeout): void {
            $this->responseTimer = null;
            $this->failed(ConnectionFailed::noSubAck($timeout));
        });

        $deferred->promise()->then(function (SubAck $ack) use ($filters): void {
            $this->cancelResponseTimer();
            $refused = [];

            foreach (array_keys($filters) as $i => $filter) {
                $code = $ack->reasonCodes[$i] ?? ReasonCode::UnspecifiedError;

                if ($code->isError()) {
                    $refused[$filter] = $code;
                }
            }

            $refused === [] ? $this->settleStart(null) : $this->finish(new SubscriptionRefused($refused));
        }, static function (): void {
            // The connection was lost before the SUBACK; the reconnect subscribes again.
        });

        $this->write(new Subscribe($id, $filters));
    }

    private function inbound(Publish $packet): void
    {
        if ($this->guard !== null && ! ($this->guard)()) {
            $this->finish(ConnectionClosed::guarded());

            return;
        }

        if ($packet->qos > 1) {
            $this->protocolError('QoS 2 is not supported.', ReasonCode::QosNotSupported);

            return;
        }

        if ($this->holdingInbound) {
            return;
        }

        $socket = $this->socket;
        $this->receivedCount++;

        if ($this->onMessage !== null) {
            try {
                ($this->onMessage)(new Message(
                    topic: $packet->topic,
                    payload: $packet->payload,
                    qos: $packet->qos,
                    retain: $packet->retain,
                    properties: $packet->properties,
                ));
            } catch (Throwable $e) {
                $this->report($e);
            }
        }

        if ($packet->qos === 1 && $packet->packetId !== null && $socket === $this->socket) {
            $this->write(new PubAck($packet->packetId));
        }
    }

    private function pubAck(PubAck $ack): void
    {
        $outbound = $this->inFlight[$ack->packetId] ?? null;

        if ($outbound === null) {
            return;
        }

        unset($this->inFlight[$ack->packetId]);
        $this->ids->release($ack->packetId);

        $ack->reasonCode->isError()
            ? $outbound->deferred->reject(PublishRejected::byBroker($outbound->packet->topic, $ack->reasonCode, $ack->reasonString))
            : $outbound->deferred->resolve(null);

        $this->flush();
    }

    private function subAck(SubAck $ack): void
    {
        $deferred = $this->subAcks[$ack->packetId] ?? null;

        if ($deferred === null) {
            return;
        }

        unset($this->subAcks[$ack->packetId]);
        $this->ids->release($ack->packetId);
        $deferred->resolve($ack);
    }

    private function disconnected(Disconnect $packet): void
    {
        $closed = ConnectionClosed::byBroker($packet);

        $closed->isSessionTakenOver() ? $this->finish($closed) : $this->failed($closed);
    }

    /**
     * Sends waiting publishes while connected, urgent ones first, each queue
     * in order, keeping QoS 1 within the broker's Receive Maximum.
     */
    private function flush(): void
    {
        while ($this->state === ConnectionState::Connected && $this->socket !== null) {
            $queue = $this->urgentQueue->isEmpty() ? $this->queue : $this->urgentQueue;

            if ($queue->isEmpty()) {
                break;
            }

            if ($queue->bottom()->packet->qos === 1 && count($this->inFlight) >= $this->limits->receiveMaximum) {
                return;
            }

            $this->send($queue->dequeue());
        }

        $this->notifyIdle();
    }

    private function send(OutboundPublish $outbound): void
    {
        $packet = $outbound->packet;

        if ($packet->qos > $this->limits->maximumQos) {
            $outbound->deferred->reject(PublishRejected::qosNotSupported($packet->topic, $packet->qos, $this->limits->maximumQos));

            return;
        }

        if ($packet->retain && ! $this->limits->retainAvailable) {
            $outbound->deferred->reject(PublishRejected::retainNotSupported($packet->topic));

            return;
        }

        $id = $packet->qos > 0 ? $this->ids->acquire() : null;
        $packet = new Publish($packet->topic, $packet->payload, $packet->qos, $packet->retain, false, $id, $packet->properties);

        try {
            $bytes = $this->codec->encode($packet);
        } catch (ProtocolException $e) {
            $this->releaseId($id);
            $outbound->deferred->reject(PublishRejected::unencodable($packet->topic, $e->getMessage()));

            return;
        }

        $maximum = $this->limits->maximumPacketSize;

        if ($maximum !== null && strlen($bytes) > $maximum) {
            $this->releaseId($id);
            $outbound->deferred->reject(PublishRejected::tooLarge($packet->topic, strlen($bytes), $maximum));

            return;
        }

        if ($id !== null) {
            $outbound->packet = $packet;
            $this->inFlight[$id] = $outbound;
        }

        if ($this->tracer !== null) {
            ($this->tracer)('out', $packet);
        }

        $this->socket?->write($bytes);
        $this->publishedCount++;

        if ($id === null) {
            $outbound->deferred->resolve(null);
        }
    }

    private function write(Packet $packet): void
    {
        $socket = $this->socket;

        if ($socket === null) {
            return;
        }

        if ($this->tracer !== null) {
            ($this->tracer)('out', $packet);
        }

        $socket->write($this->codec->encode($packet));
    }

    private function protocolError(string $detail, ReasonCode $reasonCode, ?Throwable $previous = null): void
    {
        $socket = $this->socket;

        if ($this->options->protocol === ProtocolVersion::V5_0 && $socket !== null) {
            $this->write(new Disconnect($reasonCode));
            $this->socket = null;
            $socket->end();
        }

        $this->failed(ConnectionClosed::protocolError($detail, $reasonCode, $previous));
    }

    /**
     * A transient failure: drop the socket and try again after the backoff.
     */
    private function failed(Throwable $reason): void
    {
        if ($this->state === ConnectionState::Stopping || $this->state === ConnectionState::Disconnected) {
            return;
        }

        $this->dropSocket();
        $this->disconnectedAt = microtime(true);
        $this->failedAttempts++;
        $this->lastError = $reason;
        $this->report($reason);

        if (! $this->options->backoff->allows($this->failedAttempts)) {
            $this->finish(ConnectionFailed::gaveUp($this->failedAttempts, $reason));

            return;
        }

        $this->transitionTo(ConnectionState::Reconnecting);

        if ($this->state === ConnectionState::Reconnecting) {
            $this->retryTimer = $this->loop->addTimer(
                $this->options->backoff->delay($this->failedAttempts),
                $this->attempt(...),
            );
        }
    }

    /**
     * Ends the connection for good.
     */
    private function finish(?Throwable $reason): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        if ($this->retryTimer !== null) {
            $this->loop->cancelTimer($this->retryTimer);
            $this->retryTimer = null;
        }

        $wasActive = $this->state !== ConnectionState::Disconnected;
        $this->lastError = $reason;
        $this->dropSocket();

        if ($wasActive) {
            $this->disconnectedAt = microtime(true);
            $this->transitionTo(ConnectionState::Stopping);
        }

        $closed = $reason ?? ConnectionClosed::stopped();

        foreach ($this->inFlight as $id => $outbound) {
            $this->ids->release($id);
            $outbound->deferred->reject($closed);
        }

        $this->inFlight = [];

        foreach ([$this->urgentQueue, $this->queue] as $queue) {
            while (! $queue->isEmpty()) {
                $queue->dequeue()->deferred->reject($closed);
            }
        }

        $this->notifyIdle();

        if ($wasActive) {
            $this->transitionTo(ConnectionState::Disconnected);
        }

        $this->settleStart($closed);

        foreach ($this->closeListeners as $listener) {
            $listener($reason);
        }
    }

    private function dropSocket(): void
    {
        $this->cancelResponseTimer();
        $this->keepalive?->stop();
        $this->keepalive = null;

        $socket = $this->socket;
        $this->socket = null;
        $this->decoder = null;
        $socket?->close();

        foreach ($this->subAcks as $id => $deferred) {
            $this->ids->release($id);
            $deferred->reject(ConnectionClosed::lost());
        }

        $this->subAcks = [];
    }

    private function settleStart(?Throwable $reason): void
    {
        if ($this->startSettled || $this->started === null) {
            return;
        }

        $this->startSettled = true;
        $reason === null ? $this->started->resolve(null) : $this->started->reject($reason);
    }

    private function notifyIdle(): void
    {
        if ($this->idleWaiters === [] || $this->inFlight !== [] || $this->queuedCount() > 0) {
            return;
        }

        $waiters = $this->idleWaiters;
        $this->idleWaiters = [];

        foreach ($waiters as $waiter) {
            $waiter->resolve(null);
        }
    }

    private function cancelResponseTimer(): void
    {
        if ($this->responseTimer !== null) {
            $this->loop->cancelTimer($this->responseTimer);
            $this->responseTimer = null;
        }
    }

    private function releaseId(?int $id): void
    {
        if ($id !== null) {
            $this->ids->release($id);
        }
    }

    private function report(Throwable $error): void
    {
        foreach ($this->errorListeners as $listener) {
            $listener($error);
        }
    }

    private function name(Packet $packet): string
    {
        return strtoupper((new ReflectionClass($packet))->getShortName());
    }
}
