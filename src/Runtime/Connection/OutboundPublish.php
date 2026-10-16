<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use React\Promise\Deferred;
use Whatef\Harken\Protocol\Packet\Publish;

/**
 * A publish waiting to be sent or acknowledged. The packet gains its packet
 * id when sent, and its DUP flag when sent again after a reconnect.
 *
 * @internal
 */
final class OutboundPublish
{
    /** @var Deferred<null> */
    public readonly Deferred $deferred;

    public function __construct(public Publish $packet)
    {
        $this->deferred = new Deferred;
    }
}
