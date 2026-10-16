<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

/**
 * Renders a MetricsRegistry in the Prometheus text exposition format
 * (version 0.0.4).
 */
final class PrometheusFormat
{
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /** Help text of the metrics Harken records. */
    public const array HELP = [
        'harken_messages_received_total' => 'Inbound MQTT messages received, by connection (main or leader).',
        'harken_messages_published_total' => 'MQTT messages published, by connection.',
        'harken_messages_handled_total' => 'Messages a route handled, by route.',
        'harken_messages_unmatched_total' => 'Messages that matched no route.',
        'harken_notifications_handled_total' => 'PostgreSQL notifications handled, by channel.',
        'harken_handler_seconds' => 'Time an inline handler (or a queue hand-off) took.',
        'harken_handlers_slow_total' => 'Handler runs over the slow threshold.',
        'harken_handlers_failed_total' => 'Handler runs that threw.',
        'harken_connected' => 'Whether a broker connection is connected (1) or not (0).',
        'harken_reconnects_total' => 'Successful reconnects, by connection.',
        'harken_leader' => 'Whether this replica leads (1) or stands by (0).',
        'harken_leader_takeovers_total' => 'Times this replica became leader.',
        'harken_leader_takeover_seconds' => 'From taking the leader lock to the leader connection being subscribed.',
        'harken_leader_gap_seconds' => 'From the previous leader\'s last sign of life to this leader being subscribed.',
        'harken_leader_last_takeover_seconds' => 'Takeover time of the last time this replica became leader.',
        'harken_leader_last_gap_seconds' => 'Gap of the last time this replica became leader.',
        'harken_outbox_published_total' => 'Outbox entries published and removed, by stream.',
        'harken_outbox_dropped_total' => 'Outbox entries dropped (malformed or refused by the broker), by stream.',
        'harken_outbox_latency_seconds' => 'From XADD to the broker acknowledging the publish, by stream.',
        'harken_outbox_waiting' => 'Entries waiting in an outbox stream.',
        'harken_memory_bytes' => 'Memory allocated by the runtime process.',
        'harken_loop_lag_seconds' => 'How late the last status heartbeat timer fired.',
        'harken_start_time_seconds' => 'Unix time the runtime process started.',
    ];

    public static function render(MetricsRegistry $registry): string
    {
        $lines = [];

        foreach ($registry->counters() as $name => $series) {
            self::header($lines, $name, 'counter');

            foreach ($series as [$labels, $value]) {
                $lines[] = $name.self::labels($labels).' '.self::number($value);
            }
        }

        foreach ($registry->gauges() as $name => $series) {
            self::header($lines, $name, 'gauge');

            foreach ($series as [$labels, $value]) {
                $lines[] = $name.self::labels($labels).' '.self::number($value);
            }
        }

        foreach ($registry->histograms() as $name => $series) {
            self::header($lines, $name, 'histogram');
            $bounds = MetricsRegistry::buckets($name);

            foreach ($series as [$labels, $histogram]) {
                foreach ($bounds as $i => $bound) {
                    $lines[] = $name.'_bucket'.self::labels($labels + ['le' => self::number($bound)]).' '.$histogram['buckets'][$i];
                }

                $lines[] = $name.'_bucket'.self::labels($labels + ['le' => '+Inf']).' '.$histogram['count'];
                $lines[] = $name.'_sum'.self::labels($labels).' '.self::number($histogram['sum']);
                $lines[] = $name.'_count'.self::labels($labels).' '.$histogram['count'];
            }
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $lines
     */
    private static function header(array &$lines, string $name, string $type): void
    {
        if (isset(self::HELP[$name])) {
            $lines[] = "# HELP {$name} ".self::HELP[$name];
        }

        $lines[] = "# TYPE {$name} {$type}";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private static function number(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
