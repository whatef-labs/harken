<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

use RuntimeException;
use Throwable;

/**
 * Bytes that are not a valid packet, or a packet that cannot be encoded.
 *
 * $reasonCode is what an MQTT 5 client sends in its DISCONNECT before closing
 * the connection over a decoding failure.
 */
final class ProtocolException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ReasonCode $reasonCode = ReasonCode::MalformedPacket,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $reasonCode->value, $previous);
    }

    public static function protocolError(string $message): self
    {
        return new self($message, ReasonCode::ProtocolError);
    }
}
