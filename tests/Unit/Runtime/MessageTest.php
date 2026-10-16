<?php

declare(strict_types=1);

use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Message;

it('exposes route parameters', function () {
    $message = new Message('device/7/up', '{}', params: ['id' => '7']);

    expect($message->param('id'))->toBe('7')
        ->and($message->param('missing'))->toBeNull()
        ->and($message->param('missing', 'x'))->toBe('x');
});

it('keeps arbitrary payload bytes through serialization into a JSON queue payload', function () {
    $payload = random_bytes(512)."\x00\xc3\x28";
    $properties = new Properties(
        contentType: 'application/octet-stream',
        responseTopic: 'reply/1',
        correlationData: random_bytes(16),
        messageExpiryInterval: 60,
        payloadFormatIndicator: true,
        userProperties: ['trace' => 'abc', 'tag' => ['a', 'b']],
        subscriptionIdentifiers: [3],
    );
    $message = new Message('a/b', $payload, 1, true, ['id' => '1'], $properties);

    $json = json_encode(['command' => serialize($message)], JSON_THROW_ON_ERROR);
    $restored = unserialize(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['command']);

    expect($restored)->toEqual($message)
        ->and($restored->payload)->toBe($payload);
});

it('rejects tampered serialized data', function () {
    unserialize('O:29:"Whatef\Harken\Runtime\Message":1:{s:5:"topic";i:1;}');
})->throws(UnexpectedValueException::class);

it('rejects serialized data with malformed properties', function () {
    $data = (new Message('a', 'b'))->__serialize();
    $data['properties'] = ['message_expiry_interval' => 'soon'];

    (new ReflectionClass(Message::class))->newInstanceWithoutConstructor()->__unserialize($data);
})->throws(UnexpectedValueException::class);
