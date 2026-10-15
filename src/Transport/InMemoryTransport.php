<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

use LogicException;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Transport double for tests. Every connect() creates a linked pair: the
 * caller gets one end, the test drives the other end as if it were a broker.
 */
final class InMemoryTransport implements Transport
{
    /** @var list<InMemoryConnection> */
    private array $peers = [];

    /** @var list<Endpoint> */
    private array $endpoints = [];

    private ?Throwable $failNext = null;

    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    public function connect(Endpoint $endpoint): PromiseInterface
    {
        $this->endpoints[] = $endpoint;

        if ($this->failNext !== null) {
            $reason = $this->failNext;
            $this->failNext = null;

            return reject($reason);
        }

        [$client, $peer] = InMemoryConnection::pair();
        $this->peers[] = $peer;

        return resolve($client);
    }

    public function failNextConnect(Throwable $reason): void
    {
        $this->failNext = $reason;
    }

    /**
     * The broker side of the most recent connection.
     */
    public function peer(): InMemoryConnection
    {
        $last = array_key_last($this->peers);

        if ($last === null) {
            throw new LogicException('No connection was opened.');
        }

        return $this->peers[$last];
    }

    public function connectionCount(): int
    {
        return count($this->peers);
    }

    /**
     * @return list<Endpoint>
     */
    public function endpoints(): array
    {
        return $this->endpoints;
    }
}
