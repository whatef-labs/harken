<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Wire;

use InvalidArgumentException;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\ReasonCode;

/**
 * Cuts complete control packets out of a byte stream (§2.1).
 *
 * The fixed header is the same in MQTT 3.1.1 and 5, so both codecs frame with
 * this class. A packet larger than the limit is rejected as soon as its
 * fixed header arrives, before its body is buffered.
 *
 * @internal
 */
final class Framer
{
    /** First byte, four remaining-length bytes and the largest remaining length. */
    public const int PROTOCOL_MAXIMUM = 268435460;

    private string $buffer = '';

    public function __construct(private readonly int $maximumPacketSize = self::PROTOCOL_MAXIMUM)
    {
        if ($maximumPacketSize < 2 || $maximumPacketSize > self::PROTOCOL_MAXIMUM) {
            throw new InvalidArgumentException("Maximum packet size [{$maximumPacketSize}] is out of range 2..".self::PROTOCOL_MAXIMUM.'.');
        }
    }

    /**
     * @return list<Frame>
     *
     * @throws ProtocolException on a malformed fixed header or an oversized packet.
     */
    public function push(string $bytes): array
    {
        $this->buffer .= $bytes;
        $buffer = $this->buffer;
        $length = strlen($buffer);
        $offset = 0;
        $frames = [];

        while ($length - $offset >= 2) {
            $remaining = 0;
            $multiplier = 1;
            $cursor = $offset + 1;

            do {
                if ($cursor - $offset > 4) {
                    throw new ProtocolException('Remaining length is longer than four bytes.');
                }

                if ($cursor >= $length) {
                    break 2;
                }

                $digit = ord($buffer[$cursor++]);
                $remaining += ($digit & 0x7F) * $multiplier;
                $multiplier *= 128;
            } while (($digit & 0x80) !== 0);

            $headerLength = $cursor - $offset;
            $total = $headerLength + $remaining;

            if ($total > $this->maximumPacketSize) {
                throw new ProtocolException(
                    "Packet of {$total} bytes exceeds the maximum packet size of {$this->maximumPacketSize}.",
                    ReasonCode::PacketTooLarge,
                );
            }

            if ($length - $offset < $total) {
                break;
            }

            $first = ord($buffer[$offset]);
            $type = $first >> 4;

            if ($type === 0) {
                throw new ProtocolException('Packet type 0 is reserved.');
            }

            $frames[] = new Frame($type, $first & 0x0F, substr($buffer, $offset + $headerLength, $remaining), substr($buffer, $offset, $headerLength));
            $offset += $total;
        }

        if ($offset > 0) {
            $this->buffer = substr($buffer, $offset);
        }

        return $frames;
    }
}
