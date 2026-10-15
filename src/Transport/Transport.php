<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

/**
 * Opens a byte stream to a broker. Packet framing happens above this layer.
 */
interface Transport
{
    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    public function connect(Endpoint $endpoint): PromiseInterface;
}
