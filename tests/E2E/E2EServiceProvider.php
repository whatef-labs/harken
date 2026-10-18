<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\E2E;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;
use RuntimeException;
use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Tests\E2E\Load\DeviceReport;
use Whatef\Harken\Tests\E2E\Load\SlowReport;

/**
 * Routes for end-to-end runs. Every message on E2E_TOPIC is appended to the
 * E2E_LOG file as "pid payload unix-time", so a test can tell which process
 * handled what and when. E2E_DISTRIBUTION picks leader (default), shared or
 * broadcast; E2E_HANDLER_MS makes the handler block for that long. A
 * leader timer logs "pid timer unix-time" five times a second.
 *
 * `e2e:publish {topic} {payload} {--urgent}` publishes through the facade,
 * which outside the runtime means the outbox.
 *
 * With HARKEN_METRICS_DRIVER=pulse the application also runs Livewire and
 * Laravel Pulse, whose dashboard (/pulse) shows the Harken cards; see
 * demo.php. Other runs leave Pulse out, so it cannot weigh on measurements.
 */
final class E2EServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (getenv('HARKEN_METRICS_DRIVER') === 'pulse') {
            $this->app->register(LivewireServiceProvider::class);
            $this->app->register(PulseServiceProvider::class);
        }
    }

    public function boot(): void
    {
        View::prependNamespace('pulse', __DIR__.'/pulse');

        if (getenv('E2E_MODE') === 'load') {
            // load.php and bench.php: light reports, on the leader (or shared
            // with E2E_DISTRIBUTION=shared), inline (or queued with
            // E2E_QUEUED), resolved per message (or once with E2E_SINGLETON),
            // QoS 1 (or 0 with E2E_QOS=0). Slow reports inline or queued (E2E_SLOW).
            $report = Harken::topic('load/{id}/up', DeviceReport::class)->qos(getenv('E2E_QOS') === '0' ? 0 : 1);

            if (getenv('E2E_DISTRIBUTION') === 'shared') {
                $report->shared('bench');
            }

            if (getenv('E2E_QUEUED')) {
                $report->queued();
            }

            if (getenv('E2E_SINGLETON')) {
                $this->app->singleton(DeviceReport::class);
            }

            $slow = Harken::topic('load/{id}/slow', SlowReport::class)->qos(1);

            if (getenv('E2E_SLOW') === 'queued') {
                $slow->queued();
            }

            if (getenv('E2E_SOAK')) {
                // soak.php: the other paths a long run has to go through.
                Harken::topic('load/{id}/bad', static fn () => throw new RuntimeException('soak: a failing handler'))->qos(1);
                Harken::listen('harken_soak', static function (): void {});
                Harken::every(1, static function (): void {});
            }

            return;
        }

        // bench.php: what Harken::publish() costs a web request (one XADD each).
        Artisan::command('e2e:publish-bench {count=10000}', function (string $count): void {
            $samples = [];

            for ($i = 0; $i < (int) $count; $i++) {
                $start = hrtime(true);
                Harken::publish("bench/cmd/{$i}", '{"open":true}', 1);
                $samples[] = (hrtime(true) - $start) / 1e6;
            }

            sort($samples);
            $pick = static fn (float $p): float => round($samples[(int) min(count($samples) - 1, floor($p * count($samples)))], 3);
            $this->line((string) json_encode(['calls' => count($samples), 'p50_ms' => $pick(0.5), 'p99_ms' => $pick(0.99), 'max_ms' => round(end($samples), 3)]));
        });

        $topic = (string) (getenv('E2E_TOPIC') ?: 'harken-e2e/+/up');
        $log = (string) (getenv('E2E_LOG') ?: sys_get_temp_dir().'/harken-e2e.log');
        $blockMs = (int) (getenv('E2E_HANDLER_MS') ?: 0);

        $route = Harken::topic($topic, static function (Message $message) use ($log, $blockMs): void {
            // Busy, not sleeping: a blocking call that signals do not cut short.
            $until = microtime(true) + $blockMs / 1000;

            while (microtime(true) < $until) {
                // block the loop
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

        // A leader timer (the default for Harken::every()) logs "pid timer time".
        Harken::every(0.2, static function () use ($log): void {
            file_put_contents($log, sprintf("%d timer %.6f\n", getmypid(), microtime(true)), FILE_APPEND | LOCK_EX);
        });
    }
}
