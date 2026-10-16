<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Mqtt5;

use LogicException;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\Wire\Writer;

/**
 * Builds the properties of one outbound packet (§2.2.2). Null values are
 * left out.
 *
 * @internal
 */
final class PropertyBlock
{
    private readonly Writer $writer;

    public function __construct()
    {
        $this->writer = new Writer;
    }

    /**
     * @throws ProtocolException when the value is not valid for the property.
     */
    public function add(Property $property, int|string|null $value): self
    {
        if ($value === null) {
            return $this;
        }

        $label = $property->label();
        $this->writer->varint($property->value);

        match ($property->type()) {
            PropertyType::Byte => $this->writer->byte(self::flag($property, $value)),
            PropertyType::TwoByteInteger => $this->writer->uint16(self::int($property, $value)),
            PropertyType::FourByteInteger => $this->writer->uint32(self::int($property, $value)),
            PropertyType::VariableByteInteger => $this->writer->varint(self::int($property, $value)),
            PropertyType::Utf8String => $this->writer->utf8(self::string($property, $value), $label),
            PropertyType::BinaryData => $this->writer->binary(self::string($property, $value), $label),
            PropertyType::Utf8StringPair => throw new LogicException('Use userProperties() for user properties.'),
        };

        return $this;
    }

    /**
     * @param  array<string, string|list<string>>  $properties
     */
    public function userProperties(array $properties): self
    {
        foreach (Properties::pairs($properties) as [$name, $value]) {
            $this->writer->varint(Property::UserProperty->value)
                ->utf8($name, 'user property name')
                ->utf8($value, 'user property value');
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->writer->length() === 0;
    }

    /**
     * Property length followed by the properties.
     */
    public function bytes(): string
    {
        return Writer::varintBytes($this->writer->length()).$this->writer->bytes();
    }

    private static function int(Property $property, int|string $value): int
    {
        if (! is_int($value)) {
            throw new LogicException("The {$property->label()} property takes an integer.");
        }

        if ($value === 0 && $property->zeroIsInvalid()) {
            throw new ProtocolException("The {$property->label()} property cannot be 0.");
        }

        return $value;
    }

    private static function flag(Property $property, int|string $value): int
    {
        if ($value !== 0 && $value !== 1) {
            throw new ProtocolException("The {$property->label()} property must be 0 or 1.");
        }

        return $value;
    }

    private static function string(Property $property, int|string $value): string
    {
        if (! is_string($value)) {
            throw new LogicException("The {$property->label()} property takes a string.");
        }

        return $value;
    }
}
