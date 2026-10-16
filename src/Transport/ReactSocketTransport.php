<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

final class ReactSocketTransport implements Transport
{
    public function __construct(private readonly ?LoopInterface $loop = null) {}

    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    public function connect(Endpoint $endpoint): PromiseInterface
    {
        $connector = new Connector([
            'timeout' => $endpoint->connectTimeout,
            'tls' => $endpoint->tls?->toStreamContext() ?? [],
            // Reading the system DNS configuration is a blocking call (and shells
            // out on Windows), so skip it when there is nothing to resolve.
            'dns' => $endpoint->hostIsIp() ? false : ($endpoint->nameserver ?? true),
        ], $this->loop);

        return $connector->connect($endpoint->uri());
    }
}
