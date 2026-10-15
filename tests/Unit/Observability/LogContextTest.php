<?php

declare(strict_types=1);

use Whatef\Harken\Observability\LogContext;
use Whatef\Harken\Runtime\Message;

it('leaves the payload out by default', function () {
    $context = (new LogContext)->message(new Message('a/1', 'secret-payload', 1, false, ['id' => '1']));

    expect($context)->toBe(['topic' => 'a/1', 'qos' => 1, 'retain' => false, 'bytes' => 14, 'params' => ['id' => '1']])
        ->and(json_encode($context))->not->toContain('secret-payload');
});

it('includes the payload when enabled, base64-encoding binary data', function () {
    $context = new LogContext(includePayload: true);

    expect($context->message(new Message('a', 'text'))['payload'])->toBe('text')
        ->and($context->message(new Message('a', "\xff\x00"))['payload'])->toBe('base64:/wA=');
});

it('redacts credentials recursively', function () {
    expect(LogContext::redact([
        'host' => 'broker',
        'password' => 'hunter2',
        'tls' => ['passphrase' => 'p', 'cafile' => '/ca.crt'],
        'api_key' => 'k',
        'username' => 'svc',
        'token' => null,
    ]))->toBe([
        'host' => 'broker',
        'password' => '********',
        'tls' => ['passphrase' => '********', 'cafile' => '/ca.crt'],
        'api_key' => '********',
        'username' => 'svc',
        'token' => null,
    ]);
});
