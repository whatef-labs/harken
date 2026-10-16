<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\E2E\Load;

use RuntimeException;
use Whatef\Harken\Runtime\Message;

/**
 * A realistic light handler for load runs: parse the JSON report, verify its
 * HMAC signature and update per-device state in memory.
 */
final class DeviceReport
{
    public const string KEY = 'harken-e2e-load';

    /** @var array<string, array{int, float}> */
    private static array $devices = [];

    public function __invoke(Message $message): void
    {
        /** @var array{seq: int, body: string, sig: string} $report */
        $report = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);

        if (! hash_equals(hash_hmac('sha256', $report['body'], self::KEY), $report['sig'])) {
            throw new RuntimeException('Bad signature.');
        }

        self::$devices[(string) $message->param('id')] = [$report['seq'], microtime(true)];
    }

    /**
     * About 400 bytes, like a periodic device report.
     */
    public static function payload(int $seq): string
    {
        $body = base64_encode(random_bytes(220));

        return (string) json_encode(['seq' => $seq, 'body' => $body, 'sig' => hash_hmac('sha256', $body, self::KEY)]);
    }
}
