<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\E2E;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Tests\E2E\Load\DeviceReport;
use Whatef\Harken\Tests\E2E\Load\SlowReport;

/**
 * Routes for end-to-end runs. Every message on E2E_TOPIC is appended to the
 * E2E_LOG file as "pid payload unix-time", so a test can tell which process
 * handled what and when. E2E_DISTRIBUTION picks leader (default), shared or
 * broadcast; E2E_HANDLER_MS makes the handler block for that long.
 *
 * `e2e:publish {topic} {payload} {--urgent}` publishes through the facade,
 * which outside the runtime means the outbox.
 */
final class E2EServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (getenv('E2E_MODE') === 'load') {
            // load.php: light reports inline; slow reports inline or queued (E2E_SLOW).
            Harken::topic('load/{id}/up', DeviceReport::class)->qos(1);
            $slow = Harken::topic('load/{id}/slow', SlowReport::class)->qos(1);

            if (getenv('E2E_SLOW') === 'queued') {
                $slow->queued();
            }

            return;
        }

        $topic = (string) (getenv('E2E_TOPIC') ?: 'harken-e2e/+/up');
        $log = (string) (getenv('E2E_LOG') ?: sys_get_temp_dir().'/harken-e2e.log');
        $blockMs = (int) (getenv('E2E_HANDLER_MS') ?: 0);

        $route = Harken::topic($topic, static function (Message $message) use ($log, $blockMs): void {
            if ($blockMs > 0) {
                usleep($blockMs * 1000);
            }

            file_put_contents($log, sprintf("%d %s %.6f\n", getmypid(), $message->payload, microtime(true)), FILE_APPEND | LOCK_EX);
        })->qos(1);

        Artisan::command('e2e:publish {topic} {payload} {--urgent}', function (string $topic, string $payload): void {
            $this->option('urgent')
                ? Harken::urgent()->publish($topic, $payload, 1)
                : Harken::publish($topic, $payload, 1);
        });

        match (getenv('E2E_DISTRIBUTION') ?: 'leader') {
            'shared' => $route->shared('e2e'),
            'broadcast' => $route->broadcast(),
            default => $route->leader(),
        };
    }
}
