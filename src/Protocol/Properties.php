<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

use InvalidArgumentException;
use Whatef\Harken\Protocol\Wire\Utf8;

/**
 * MQTT 5 message properties, carried by PUBLISH packets and will messages.
 *
 * Inbound messages expose them as Message::$properties, and publish() takes
 * them as its last argument. MQTT 3.1.1 cannot carry them: on a 3.1.1
 * connection they are always empty inbound and dropped outbound.
 */
final readonly class Properties
{
    public const int MAX_EXPIRY_INTERVAL = 4294967295;

    public const int MAX_SUBSCRIPTION_IDENTIFIER = 268435455;

    /**
     * Name => value. A name sent more than once maps to a list of its values
     * in the order received. The relative order of different names is not
     * kept.
     *
     * @var array<string, string|list<string>>
     */
    public array $userProperties;

    /**
     * @param  string|null  $contentType  Describes the payload, e.g. a MIME type.
     * @param  string|null  $responseTopic  Topic the receiver should publish its response to.
     * @param  string|null  $correlationData  Binary data the requester uses to match the response.
     * @param  int|null  $messageExpiryInterval  Seconds the broker keeps the message for subscribers it has not reached yet.
     * @param  bool  $payloadFormatIndicator  True when the payload is UTF-8 text.
     * @param  array<string, string|list<string>>  $userProperties  Name => value, or name => list of values.
     * @param  list<int>  $subscriptionIdentifiers  Set by the broker on inbound messages only.
     *
     * @throws InvalidArgumentException when a value cannot be sent as an MQTT 5 property.
     */
    public function __construct(
        public ?string $contentType = null,
        public ?string $responseTopic = null,
        public ?string $correlationData = null,
        public ?int $messageExpiryInterval = null,
        public bool $payloadFormatIndicator = false,
        array $userProperties = [],
        public array $subscriptionIdentifiers = [],
    ) {
        self::assertString('content type', $contentType);
        self::assertString('response topic', $responseTopic);

        if ($responseTopic !== null && ($responseTopic === '' || strpbrk($responseTopic, '+#') !== false)) {
            throw new InvalidArgumentException('The response topic must be a topic name without wildcards.');
        }

        if ($correlationData !== null && strlen($correlationData) > Utf8::MAX_BYTES) {
            throw new InvalidArgumentException('The correlation data is longer than 65535 bytes.');
        }

        if ($messageExpiryInterval !== null && ($messageExpiryInterval < 0 || $messageExpiryInterval > self::MAX_EXPIRY_INTERVAL)) {
            throw new InvalidArgumentException("The message expiry interval [{$messageExpiryInterval}] is out of range 0..4294967295.");
        }

        foreach ($subscriptionIdentifiers as $id) {
            if ($id < 1 || $id > self::MAX_SUBSCRIPTION_IDENTIFIER) {
                throw new InvalidArgumentException("The subscription identifier [{$id}] is out of range 1..268435455.");
            }
        }

        foreach (self::pairs($userProperties) as [$name, $value]) {
            self::assertString("user property name [{$name}]", $name);
            self::assertString("user property [{$name}]", $value);
        }

        $this->userProperties = $userProperties;
    }

    public function isEmpty(): bool
    {
        return $this->contentType === null
            && $this->responseTopic === null
            && $this->correlationData === null
            && $this->messageExpiryInterval === null
            && ! $this->payloadFormatIndicator
            && $this->userProperties === []
            && $this->subscriptionIdentifiers === [];
    }

    /**
     * The first value of a user property, or null when it is absent.
     */
    public function userProperty(string $name): ?string
    {
        $value = $this->userProperties[$name] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    /**
     * User properties as (name, value) pairs in wire order.
     *
     * @return list<array{string, string}>
     */
    public function userPropertyPairs(): array
    {
        return self::pairs($this->userProperties);
    }

    /**
     * @param  array<array-key, string|list<string>>  $userProperties
     * @return list<array{string, string}>
     */
    public static function pairs(array $userProperties): array
    {
        $pairs = [];

        foreach ($userProperties as $name => $values) {
            foreach ((array) $values as $value) {
                $pairs[] = [(string) $name, $value];
            }
        }

        return $pairs;
    }

    /**
     * Groups (name, value) pairs into the name => value(s) form.
     *
     * @param  list<array{string, string}>  $pairs
     * @return array<string, string|list<string>>
     */
    public static function group(array $pairs): array
    {
        $grouped = [];

        foreach ($pairs as [$name, $value]) {
            if (! array_key_exists($name, $grouped)) {
                $grouped[$name] = $value;
            } elseif (is_array($grouped[$name])) {
                $grouped[$name][] = $value;
            } else {
                $grouped[$name] = [$grouped[$name], $value];
            }
        }

        return $grouped;
    }

    /**
     * A JSON-safe form for queue jobs and outbox entries. Only set
     * properties are included; correlation data is base64-encoded.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'content_type' => $this->contentType,
            'response_topic' => $this->responseTopic,
            'correlation_data' => $this->correlationData === null ? null : base64_encode($this->correlationData),
            'message_expiry_interval' => $this->messageExpiryInterval,
            'payload_format_indicator' => $this->payloadFormatIndicator ?: null,
            'user_properties' => $this->userProperties ?: null,
            'subscription_identifiers' => $this->subscriptionIdentifiers ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<array-key, mixed>  $data  As produced by toArray().
     *
     * @throws InvalidArgumentException when the data is malformed.
     */
    public static function fromArray(array $data): self
    {
        $correlation = self::optional($data, 'correlation_data', 'string');

        if ($correlation !== null) {
            $correlation = base64_decode($correlation, true);

            if ($correlation === false) {
                throw new InvalidArgumentException('Malformed properties: correlation_data is not base64.');
            }
        }

        $userProperties = $data['user_properties'] ?? [];
        $subscriptionIdentifiers = $data['subscription_identifiers'] ?? [];

        if (! is_array($userProperties) || ! self::isUserPropertyMap($userProperties)) {
            throw new InvalidArgumentException('Malformed properties: user_properties.');
        }

        if (! is_array($subscriptionIdentifiers) || ! array_is_list($subscriptionIdentifiers)
            || array_filter($subscriptionIdentifiers, is_int(...)) !== $subscriptionIdentifiers) {
            throw new InvalidArgumentException('Malformed properties: subscription_identifiers.');
        }

        /** @var array<string, string|list<string>> $userProperties */
        /** @var list<int> $subscriptionIdentifiers */
        return new self(
            contentType: self::optional($data, 'content_type', 'string'),
            responseTopic: self::optional($data, 'response_topic', 'string'),
            correlationData: $correlation,
            messageExpiryInterval: self::optional($data, 'message_expiry_interval', 'int'),
            payloadFormatIndicator: ($data['payload_format_indicator'] ?? false) === true,
            userProperties: $userProperties,
            subscriptionIdentifiers: $subscriptionIdentifiers,
        );
    }

    private static function assertString(string $what, ?string $value): void
    {
        if ($value !== null && ($problem = Utf8::problem($value)) !== null) {
            throw new InvalidArgumentException("The {$what} {$problem}.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $map
     */
    private static function isUserPropertyMap(array $map): bool
    {
        foreach ($map as $values) {
            if (is_string($values)) {
                continue;
            }

            if (! is_array($values) || ! array_is_list($values) || array_filter($values, is_string(...)) !== $values) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T of 'string'|'int'
     *
     * @param  array<array-key, mixed>  $data
     * @param  T  $type
     * @return (T is 'string' ? string|null : int|null)
     */
    private static function optional(array $data, string $key, string $type): string|int|null
    {
        $value = $data[$key] ?? null;

        if ($value === null || ($type === 'string' ? is_string($value) : is_int($value))) {
            return $value;
        }

        throw new InvalidArgumentException("Malformed properties: {$key}.");
    }
}
