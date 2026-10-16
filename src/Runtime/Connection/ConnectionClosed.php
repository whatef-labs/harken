<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use RuntimeException;
use Throwable;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\ReasonCode;

/**
 * The connection ended: lost, closed by the broker, or stopped by Harken.
 *
 * $reasonCode is set when the broker sent an MQTT 5 DISCONNECT, or when
 * Harken closed the connection over a protocol error or a keepalive timeout.
 * $beforeConnAck tells that the broker closed the connection without
 * answering CONNECT, which some brokers do for a protocol version they do
 * not support.
 */
final class ConnectionClosed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ReasonCode $reasonCode = null,
        ?Throwable $previous = null,
        public readonly bool $beforeConnAck = false,
    ) {
        parent::__construct($message, $reasonCode === null ? 0 : $reasonCode->value, $previous);
    }

    public static function lost(?Throwable $previous = null): self
    {
        $detail = $previous === null ? '' : ": {$previous->getMessage()}";

        return new self("The connection to the broker was lost{$detail}.", null, $previous);
    }

    public static function beforeConnAck(?Throwable $previous = null): self
    {
        $detail = $previous === null ? '' : ": {$previous->getMessage()}";

        return new self("The broker closed the connection before answering CONNECT{$detail}.", null, $previous, true);
    }

    public static function keepAliveTimeout(int $seconds): self
    {
        return new self("No packet from the broker within the {$seconds} s keepalive interval.", ReasonCode::KeepAliveTimeout);
    }

    public static function byBroker(Disconnect $packet): self
    {
        return new self(sprintf(
            'The broker closed the connection: %s%s.',
            $packet->reasonCode->describe(),
            $packet->reasonString === null ? '' : " ({$packet->reasonString})",
        ), $packet->reasonCode);
    }

    public static function protocolError(string $detail, ReasonCode $reasonCode, ?Throwable $previous = null): self
    {
        return new self("Closed the connection over a protocol error: {$detail}", $reasonCode, $previous);
    }

    public static function stopped(): self
    {
        return new self('The connection was stopped.');
    }

    public static function notConnected(): self
    {
        return new self('The connection is not running.');
    }

    public static function guarded(): self
    {
        return new self('The connection was closed because its guard no longer allows it to run.');
    }

    public function isSessionTakenOver(): bool
    {
        return $this->reasonCode === ReasonCode::SessionTakenOver;
    }
}
