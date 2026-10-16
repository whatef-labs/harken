<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\E2E\Load;

use RuntimeException;
use Whatef\Harken\Runtime\Message;

/**
 * A realistic light handler for load runs: parse the JSON report, verify its
 * HMAC signature and update per-device state in memory.
 *
 * When E2E_LATENCY_LOG is set, every 100th report whose payload carries the
 * time it was published ("ts") appends its end-to-end latency in
 * milliseconds (publish -> handler) to that file.
 */
final class DeviceReport
{
    public const string KEY = 'harken-e2e-load';

    /** @var array<string, array{int, float}> */
    private static array $devices = [];

    private static int $count = 0;

    public function __invoke(Message $message): void
    {
        /** @var array{seq: int, body: string, sig: string, ts?: float} $report */
        $report = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);

        if (! hash_equals(hash_hmac('sha256', $report['body'], self::KEY), $report['sig'])) {
            throw new RuntimeException('Bad signature.');
        }

        $now = microtime(true);
        self::$devices[(string) $message->param('id')] = [$report['seq'], $now];

        if (isset($report['ts']) && ++self::$count % 100 === 0 && ($log = getenv('E2E_LATENCY_LOG'))) {
            file_put_contents($log, sprintf("%.3f\n", ($now - $report['ts']) * 1000), FILE_APPEND);
        }
    }

    /**
     * About 400 bytes, like a periodic device report.
     */
    public static function payload(int $seq, ?float $publishedAt = null): string
    {
        static $bodies = [];
        $body = $bodies[$seq % 100] ??= base64_encode(random_bytes(220));
        $report = ['seq' => $seq, 'body' => $body, 'sig' => hash_hmac('sha256', $body, self::KEY)];

        if ($publishedAt !== null) {
            $report['ts'] = $publishedAt;
        }

        return (string) json_encode($report);
    }
}
