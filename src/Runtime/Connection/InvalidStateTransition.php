<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use LogicException;

final class InvalidStateTransition extends LogicException
{
    public static function between(ConnectionState $from, ConnectionState $to): self
    {
        return new self("Cannot move the connection from [{$from->value}] to [{$to->value}].");
    }
}
