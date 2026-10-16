<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Wire;

use Whatef\Harken\Protocol\ProtocolException;

/**
 * Reads MQTT data types (§1.5) from one packet body. Reading past the end is
 * a malformed packet, never a PHP warning.
 *
 * @internal
 */
final class Reader
{
    private int $offset = 0;

    private readonly int $length;

    public function __construct(private readonly string $bytes)
    {
        $this->length = strlen($bytes);
    }

    /**
     * @return int<0, 255>
     */
    public function byte(): int
    {
        return ord($this->take(1));
    }

    /**
     * @return int<0, 65535>
     */
    public function uint16(): int
    {
        /** @var array{1: int<0, 65535>} $value */
        $value = unpack('n', $this->take(2));

        return $value[1];
    }

    /**
     * @return int<0, 4294967295>
     */
    public function uint32(): int
    {
        /** @var array{1: int<0, 4294967295>} $value */
        $value = unpack('N', $this->take(4));

        return $value[1];
    }

    /**
     * Variable Byte Integer (§1.5.5).
     *
     * @return int<0, 268435455>
     */
    public function varint(): int
    {
        $value = 0;
        $multiplier = 1;

        for ($i = 0; $i < 4; $i++) {
            $digit = $this->byte();
            $value += ($digit & 0x7F) * $multiplier;

            if (($digit & 0x80) === 0) {
                /** @var int<0, 268435455> $value */
                return $value;
            }

            $multiplier *= 128;
        }

        throw new ProtocolException('Variable byte integer is longer than four bytes.');
    }

    public function utf8(string $what): string
    {
        $value = $this->take($this->uint16());

        if (($problem = Utf8::problem($value)) !== null) {
            throw new ProtocolException("The {$what} {$problem}.");
        }

        return $value;
    }

    public function binary(): string
    {
        return $this->take($this->uint16());
    }

    public function take(int $count): string
    {
        if ($count > $this->length - $this->offset) {
            throw new ProtocolException('Packet ends before its declared content.');
        }

        $value = substr($this->bytes, $this->offset, $count);
        $this->offset += $count;

        return $value;
    }

    public function rest(): string
    {
        return $this->take($this->length - $this->offset);
    }

    public function atEnd(): bool
    {
        return $this->offset === $this->length;
    }

    public function assertAtEnd(string $packet): void
    {
        if (! $this->atEnd()) {
            throw new ProtocolException("{$packet} has unexpected trailing bytes.");
        }
    }
}
