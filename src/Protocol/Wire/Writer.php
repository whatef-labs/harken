<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Wire;

use Whatef\Harken\Protocol\ProtocolException;

/**
 * Writes MQTT data types (§1.5). Values that do not fit their type are a
 * ProtocolException, so an invalid packet is never put on the wire.
 *
 * @internal
 */
final class Writer
{
    public const int MAX_VARINT = 268435455;

    private string $bytes = '';

    public function byte(int $value): self
    {
        $this->bytes .= pack('C', self::range($value, 0, 255, 'byte'));

        return $this;
    }

    public function uint16(int $value): self
    {
        $this->bytes .= pack('n', self::range($value, 0, 65535, 'two byte integer'));

        return $this;
    }

    public function uint32(int $value): self
    {
        $this->bytes .= pack('N', self::range($value, 0, 4294967295, 'four byte integer'));

        return $this;
    }

    public function varint(int $value): self
    {
        $this->bytes .= self::varintBytes($value);

        return $this;
    }

    public function utf8(string $value, string $what): self
    {
        if (($problem = Utf8::problem($value)) !== null) {
            throw new ProtocolException("The {$what} {$problem}.");
        }

        $this->bytes .= pack('n', strlen($value)).$value;

        return $this;
    }

    public function binary(string $value, string $what): self
    {
        if (strlen($value) > Utf8::MAX_BYTES) {
            throw new ProtocolException("The {$what} is longer than 65535 bytes.");
        }

        $this->bytes .= pack('n', strlen($value)).$value;

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->bytes .= $bytes;

        return $this;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public static function varintBytes(int $value): string
    {
        self::range($value, 0, self::MAX_VARINT, 'variable byte integer');
        $bytes = '';

        do {
            $digit = $value % 128;
            $value = intdiv($value, 128);
            $bytes .= pack('C', $value > 0 ? $digit | 0x80 : $digit);
        } while ($value > 0);

        return $bytes;
    }

    /**
     * A complete control packet: fixed header and body.
     */
    public static function packet(int $type, int $flags, string $body): string
    {
        if (strlen($body) > self::MAX_VARINT) {
            throw new ProtocolException('Packet body is larger than 268435455 bytes.');
        }

        return pack('C', $type << 4 | $flags).self::varintBytes(strlen($body)).$body;
    }

    private static function range(int $value, int $min, int $max, string $type): int
    {
        if ($value < $min || $value > $max) {
            throw new ProtocolException("Value [{$value}] does not fit a {$type} ({$min}..{$max}).");
        }

        return $value;
    }
}
