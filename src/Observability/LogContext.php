<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

use Whatef\Harken\Runtime\Message;

/**
 * Builds structured log context. Credentials are always redacted; message
 * payloads are left out unless explicitly enabled.
 */
final readonly class LogContext
{
    private const string REDACTED = '********';

    private const string SECRET_KEY = '/(password|passphrase|secret|token|api_?key)/i';

    public function __construct(private bool $includePayload = false) {}

    /**
     * @return array<string, mixed>
     */
    public function message(Message $message): array
    {
        $context = [
            'topic' => $message->topic,
            'qos' => $message->qos,
            'retain' => $message->retain,
            'bytes' => strlen($message->payload),
        ];

        if ($message->params !== []) {
            $context['params'] = $message->params;
        }

        if ($this->includePayload) {
            $context['payload'] = mb_check_encoding($message->payload, 'UTF-8')
                ? $message->payload
                : 'base64:'.base64_encode($message->payload);
        }

        return $context;
    }

    /**
     * Replaces values of secret-looking keys, recursively.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $values[$key] = $value === null || $value === '' ? $value : self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
