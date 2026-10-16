<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use Closure;
use LogicException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Whatef\Harken\Protocol\Codec;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Publisher;
use Whatef\Harken\Transport\Transport;

/**
 * One long-lived MQTT session: connect, subscribe, publish, keepalive and
 * reconnect with resubscription.
 *
 * Skeleton: the state machine is in place; the network behaviour is filled
 * in after spike 01 (connect / QoS 1 / TLS) settles the packet handling.
 */
final class Connection implements Publisher
{
    private ConnectionState $state = ConnectionState::Disconnected;

    /** @var list<Closure(ConnectionState, ConnectionState): void> */
    private array $stateListeners = [];

    /** @var (Closure(Message): void)|null */
    private ?Closure $onMessage = null;

    /** @var array<string, int> */
    private array $subscriptions = [];

    public function __construct(
        public readonly ConnectionOptions $options,
        private readonly Transport $transport,
        private readonly Codec $codec,
        private readonly LoopInterface $loop,
    ) {}

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
     * @param  callable(Message): void  $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->onMessage = $handler(...);
    }

    /**
     * Filters (with QoS) to subscribe to after every successful connect,
     * including reconnects with a clean session.
     *
     * @param  array<string, int>  $filters
     */
    public function subscribe(array $filters): void
    {
        $this->subscriptions = $filters + $this->subscriptions;
    }

    /**
     * @return PromiseInterface<void>
     */
    public function connect(): PromiseInterface
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }

    /**
     * For QoS 1 the promise resolves after the PUBACK arrives.
     *
     * @return PromiseInterface<void>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
    }

    /**
     * Sends DISCONNECT and closes the socket. Stops reconnecting.
     *
     * @return PromiseInterface<void>
     */
    public function disconnect(): PromiseInterface
    {
        throw new LogicException(__METHOD__.' is not implemented yet.');
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
}
