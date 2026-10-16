<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Protocol\ReasonCode;

it('is empty by default', function () {
    expect((new Properties)->isEmpty())->toBeTrue()
        ->and((new Properties)->toArray())->toBe([])
        ->and((new Properties(payloadFormatIndicator: true))->isEmpty())->toBeFalse();
});

it('reads the first value of a user property', function () {
    $properties = new Properties(userProperties: ['a' => '1', 'b' => ['2', '3'], 'empty' => []]);

    expect($properties->userProperty('a'))->toBe('1')
        ->and($properties->userProperty('b'))->toBe('2')
        ->and($properties->userProperty('empty'))->toBeNull()
        ->and($properties->userProperty('missing'))->toBeNull();
});

it('flattens user properties to pairs and groups them back', function () {
    $pairs = [['a', '1'], ['b', '2'], ['a', '3'], ['42', 'x']];
    $grouped = Properties::group($pairs);

    expect($grouped)->toBe(['a' => ['1', '3'], 'b' => '2', '42' => 'x'])
        ->and(Properties::pairs($grouped))->toBe([['a', '1'], ['a', '3'], ['b', '2'], ['42', 'x']]);
});

it('round-trips through its array form, binary correlation data included', function () {
    $properties = new Properties(
        contentType: 'text/plain; charset=utf-8',
        responseTopic: 'reply/7',
        correlationData: "\x00\xff\x10binary",
        messageExpiryInterval: 0,
        payloadFormatIndicator: true,
        userProperties: ['k' => 'v', 'multi' => ['1', '2']],
        subscriptionIdentifiers: [1, 268435455],
    );

    $json = json_decode(json_encode($properties->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(Properties::fromArray($json))->toEqual($properties);
});

it('rejects values MQTT 5 cannot carry', function (Closure $make) {
    $make();
})->throws(InvalidArgumentException::class)->with([
    'invalid utf-8 content type' => [fn () => new Properties(contentType: "\xc3\x28")],
    'nul in user property' => [fn () => new Properties(userProperties: ['k' => "a\0b"])],
    'wildcard response topic' => [fn () => new Properties(responseTopic: 'reply/+')],
    'empty response topic' => [fn () => new Properties(responseTopic: '')],
    'oversized correlation data' => [fn () => new Properties(correlationData: str_repeat('x', 65536))],
    'negative expiry' => [fn () => new Properties(messageExpiryInterval: -1)],
    'expiry above 32 bits' => [fn () => new Properties(messageExpiryInterval: 4294967296)],
    'subscription identifier zero' => [fn () => new Properties(subscriptionIdentifiers: [0])],
    'malformed array form' => [fn () => Properties::fromArray(['user_properties' => ['k' => 1]])],
    'non-base64 correlation data' => [fn () => Properties::fromArray(['correlation_data' => '***'])],
]);

it('tells errors from success reason codes', function () {
    expect(ReasonCode::Success->isError())->toBeFalse()
        ->and(ReasonCode::NoMatchingSubscribers->isError())->toBeFalse()
        ->and(ReasonCode::UnspecifiedError->isError())->toBeTrue()
        ->and(ReasonCode::NotAuthorized->describe())->toBe('0x87 not authorized')
        ->and(ReasonCode::SessionTakenOver->describe())->toBe('0x8E session taken over');
});
