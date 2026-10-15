<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Message;

it('exposes route parameters', function () {
    $message = new Message('device/7/up', '{}', params: ['id' => '7']);

    expect($message->param('id'))->toBe('7')
        ->and($message->param('missing'))->toBeNull()
        ->and($message->param('missing', 'x'))->toBe('x');
});

it('keeps arbitrary payload bytes through serialization into a JSON queue payload', function () {
    $payload = random_bytes(512)."\x00\xc3\x28";
    $message = new Message('a/b', $payload, 1, true, ['id' => '1'], ['k' => 'v']);

    $json = json_encode(['command' => serialize($message)], JSON_THROW_ON_ERROR);
    $restored = unserialize(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['command']);

    expect($restored)->toEqual($message)
        ->and($restored->payload)->toBe($payload);
});

it('rejects tampered serialized data', function () {
    unserialize('O:29:"Whatef\Harken\Runtime\Message":1:{s:5:"topic";i:1;}');
})->throws(UnexpectedValueException::class);
