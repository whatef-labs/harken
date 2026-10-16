<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Mqtt5;

use LogicException;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\Wire\Reader;

/**
 * The properties decoded from one packet (§2.2.2).
 *
 * @internal
 */
final class PropertySet
{
    private static ?self $empty = null;

    /**
     * @param  array<int, int|string>  $values  Property identifier => value, for properties allowed once.
     * @param  list<array{string, string}>  $userProperties
     * @param  list<int>  $subscriptionIdentifiers
     */
    public function __construct(
        private readonly array $values = [],
        public readonly array $userProperties = [],
        public readonly array $subscriptionIdentifiers = [],
    ) {}

    /**
     * Reads a property length and the properties it covers.
     *
     * @param  array<int, true>  $allowed  Identifiers allowed in this packet.
     *
     * @throws ProtocolException
     */
    public static function read(Reader $reader, array $allowed, string $packet): self
    {
        $length = $reader->varint();

        if ($length === 0) {
            return self::$empty ??= new self;
        }

        $block = new Reader($reader->take($length));
        $values = [];
        $userProperties = [];
        $subscriptionIdentifiers = [];

        while (! $block->atEnd()) {
            $id = $block->varint();
            $property = Property::tryFrom($id) ?? throw new ProtocolException(sprintf('Unknown property 0x%02X in %s.', $id, $packet));
            $label = $property->label();

            if (! isset($allowed[$id])) {
                throw ProtocolException::protocolError("The {$label} property is not allowed in {$packet}.");
            }

            if ($property === Property::UserProperty) {
                $userProperties[] = [$block->utf8('user property name'), $block->utf8('user property value')];

                continue;
            }

            $value = match ($property->type()) {
                PropertyType::Byte => $block->byte(),
                PropertyType::TwoByteInteger => $block->uint16(),
                PropertyType::FourByteInteger => $block->uint32(),
                PropertyType::VariableByteInteger => $block->varint(),
                PropertyType::Utf8String => $block->utf8($label),
                PropertyType::BinaryData => $block->binary(),
                PropertyType::Utf8StringPair => throw new LogicException('Unreachable.'),
            };

            if ($property->type() === PropertyType::Byte && $value !== 0 && $value !== 1) {
                throw ProtocolException::protocolError("The {$label} property must be 0 or 1.");
            }

            if ($value === 0 && $property->zeroIsInvalid()) {
                throw ProtocolException::protocolError("The {$label} property cannot be 0.");
            }

            if ($property === Property::SubscriptionIdentifier && is_int($value)) {
                $subscriptionIdentifiers[] = $value;

                continue;
            }

            if (array_key_exists($id, $values)) {
                throw ProtocolException::protocolError("The {$label} property appears more than once in {$packet}.");
            }

            $values[$id] = $value;
        }

        return new self($values, $userProperties, $subscriptionIdentifiers);
    }

    public function has(Property $property): bool
    {
        return array_key_exists($property->value, $this->values);
    }

    public function int(Property $property): ?int
    {
        $value = $this->values[$property->value] ?? null;

        return is_int($value) ? $value : null;
    }

    public function string(Property $property): ?string
    {
        $value = $this->values[$property->value] ?? null;

        return is_string($value) ? $value : null;
    }

    public function bool(Property $property): ?bool
    {
        $value = $this->int($property);

        return $value === null ? null : $value === 1;
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function userPropertyMap(): array
    {
        return Properties::group($this->userProperties);
    }
}
