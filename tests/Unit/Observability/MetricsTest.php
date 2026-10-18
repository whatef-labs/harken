<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;
use Whatef\Harken\Observability\HttpEndpoint;
use Whatef\Harken\Observability\MetricsLog;
use Whatef\Harken\Observability\MetricsRegistry;
use Whatef\Harken\Observability\PrometheusFormat;

it('renders counters, gauges and histograms in the Prometheus text format', function () {
    $metrics = new MetricsRegistry;
    $metrics->increment('harken_messages_handled_total', 2, ['route' => 'sensors/{id}/temperature']);
    $metrics->increment('harken_messages_handled_total', 1, ['route' => 'sensors/{id}/temperature']);
    $metrics->increment('harken_messages_unmatched_total');
    $metrics->gauge('harken_leader', 1);
    $metrics->gauge('harken_outbox_waiting', 12, ['stream' => 'app_"quoted"\\stream']);
    $metrics->observe('harken_leader_gap_seconds', 0.3);
    $metrics->observe('harken_leader_gap_seconds', 5.2);

    expect(PrometheusFormat::render($metrics))->toBe(<<<'TEXT'
        # HELP harken_messages_handled_total Messages a route handled, by route.
        # TYPE harken_messages_handled_total counter
        harken_messages_handled_total{route="sensors/{id}/temperature"} 3
        # HELP harken_messages_unmatched_total Messages that matched no route.
        # TYPE harken_messages_unmatched_total counter
        harken_messages_unmatched_total 1
        # HELP harken_leader Whether this replica leads (1) or stands by (0).
        # TYPE harken_leader gauge
        harken_leader 1
        # HELP harken_outbox_waiting Entries waiting in an outbox stream.
        # TYPE harken_outbox_waiting gauge
        harken_outbox_waiting{stream="app_\"quoted\"\\stream"} 12
        # HELP harken_leader_gap_seconds From the previous leader's last sign of life to this leader being subscribed.
        # TYPE harken_leader_gap_seconds histogram
        harken_leader_gap_seconds_bucket{le="0.05"} 0
        harken_leader_gap_seconds_bucket{le="0.1"} 0
        harken_leader_gap_seconds_bucket{le="0.25"} 0
        harken_leader_gap_seconds_bucket{le="0.5"} 1
        harken_leader_gap_seconds_bucket{le="1"} 1
        harken_leader_gap_seconds_bucket{le="2"} 1
        harken_leader_gap_seconds_bucket{le="3"} 1
        harken_leader_gap_seconds_bucket{le="4"} 1
        harken_leader_gap_seconds_bucket{le="5"} 1
        harken_leader_gap_seconds_bucket{le="6"} 2
        harken_leader_gap_seconds_bucket{le="7"} 2
        harken_leader_gap_seconds_bucket{le="8"} 2
        harken_leader_gap_seconds_bucket{le="10"} 2
        harken_leader_gap_seconds_bucket{le="15"} 2
        harken_leader_gap_seconds_bucket{le="30"} 2
        harken_leader_gap_seconds_bucket{le="60"} 2
        harken_leader_gap_seconds_bucket{le="+Inf"} 2
        harken_leader_gap_seconds_sum 5.5
        harken_leader_gap_seconds_count 2

        TEXT);
});

it('uses fine buckets for latencies, in seconds', function () {
    $metrics = new MetricsRegistry;
    $metrics->observe('harken_handler_seconds', 0.003, ['route' => 'a']);

    expect(PrometheusFormat::render($metrics))
        ->toContain('harken_handler_seconds_bucket{route="a",le="0.0025"} 0')
        ->toContain('harken_handler_seconds_bucket{route="a",le="0.005"} 1')
        ->toContain('harken_handler_seconds_bucket{route="a",le="5"} 1')
        ->toContain('harken_handler_seconds_sum{route="a"} 0.003');
});

it('renders nothing before anything is recorded', function () {
    expect(PrometheusFormat::render(new MetricsRegistry))->toBe('');
});

it('logs what changed since the last line', function () {
    $metrics = new MetricsRegistry;
    $logger = new class extends AbstractLogger
    {
        /** @var list<array<string, mixed>> */
        public array $lines = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->lines[] = $context;
        }
    };
    $log = new MetricsLog($metrics, $logger);

    $metrics->increment('harken_messages_received_total', 10, ['connection' => 'main']);
    $metrics->observe('harken_handler_seconds', 2.0, ['route' => 'a']);
    $metrics->observe('harken_handler_seconds', 4.0, ['route' => 'a']);
    $metrics->gauge('harken_leader', 1);
    $log->write();
    $metrics->increment('harken_messages_received_total', 5, ['connection' => 'main']);
    $log->write();

    expect($logger->lines[0])->toBe([
        'handler_seconds{a}.avg' => 3.0,
        'handler_seconds{a}.count' => 2,
        'handler_seconds{a}.max' => 4.0,
        'handler_seconds{a}.p50' => 2.5,
        'handler_seconds{a}.p99' => 4.0,
        'leader' => 1.0,
        'messages_received_total{main}' => 10.0,
    ])->and($logger->lines[1])->toBe([
        'handler_seconds{a}.avg' => 0.0,
        'handler_seconds{a}.count' => 0,
        'handler_seconds{a}.max' => 0.0,
        'handler_seconds{a}.p50' => 0.0,
        'handler_seconds{a}.p99' => 0.0,
        'leader' => 1.0,
        'messages_received_total{main}' => 5.0,
    ]);
});

it('logs microsecond latencies with percentiles from the buckets of the interval and its maximum', function () {
    $metrics = new MetricsRegistry;
    $log = new MetricsLog($metrics, new NullLogger);
    $metrics->observe('harken_outbox_latency_seconds', 0.5, ['stream' => 'normal']);
    $log->summary();

    for ($i = 0; $i < 90; $i++) {
        $metrics->observe('harken_outbox_latency_seconds', 0.00003, ['stream' => 'normal']);
    }

    for ($i = 0; $i < 9; $i++) {
        $metrics->observe('harken_outbox_latency_seconds', 0.0008, ['stream' => 'normal']);
    }

    $metrics->observe('harken_outbox_latency_seconds', 0.02, ['stream' => 'normal']);

    expect($log->summary())->toBe([
        'outbox_latency_seconds{normal}.avg' => 0.000299,
        'outbox_latency_seconds{normal}.count' => 100,
        'outbox_latency_seconds{normal}.max' => 0.02,
        'outbox_latency_seconds{normal}.p50' => 0.000028,
        'outbox_latency_seconds{normal}.p99' => 0.001,
    ]);
});

/**
 * Sends one raw HTTP request to the endpoint and returns the raw response.
 */
function http_request(StreamSelectLoop $loop, HttpEndpoint $endpoint, string $request): string
{
    $client = stream_socket_client('tcp://'.$endpoint->address(), $errno, $error, 2.0);
    stream_set_blocking($client, false);
    fwrite($client, $request);
    $response = '';
    $loop->addReadStream($client, function ($stream) use (&$response, $loop) {
        $chunk = fread($stream, 65536);

        if ($chunk === '' || $chunk === false) {
            $loop->removeReadStream($stream);
            $loop->stop();

            return;
        }

        $response .= $chunk;
    });
    $timer = $loop->addTimer(2.0, fn () => $loop->stop());
    $loop->run();
    $loop->cancelTimer($timer);

    return $response;
}

it('serves metrics and health over HTTP', function () {
    $loop = new StreamSelectLoop;
    $metrics = new MetricsRegistry;
    $metrics->gauge('harken_leader', 1);
    $healthy = true;
    $endpoint = new HttpEndpoint('127.0.0.1', 0, function () use (&$healthy) {
        return [$healthy, ['state' => 'running']];
    }, $metrics);
    $endpoint->start($loop);

    $metricsResponse = http_request($loop, $endpoint, "GET /metrics HTTP/1.1\r\nHost: x\r\n\r\n");
    $health = http_request($loop, $endpoint, "GET /health HTTP/1.1\r\nHost: x\r\n\r\n");
    $healthy = false;
    $unhealthy = http_request($loop, $endpoint, "GET /health?verbose HTTP/1.0\r\n\r\n");
    $head = http_request($loop, $endpoint, "HEAD /metrics HTTP/1.1\r\n\r\n");
    $missing = http_request($loop, $endpoint, "GET /nope HTTP/1.1\r\n\r\n");
    $bad = http_request($loop, $endpoint, "BREW /coffee HTCPCP/1.0\r\n\r\n");
    $endpoint->stop();

    expect($metricsResponse)->toStartWith("HTTP/1.1 200 OK\r\nContent-Type: text/plain; version=0.0.4")
        ->toEndWith("harken_leader 1\n")
        ->and($health)->toStartWith('HTTP/1.1 200 OK')->toContain('application/json')->toEndWith("{\"state\":\"running\"}\n")
        ->and($unhealthy)->toStartWith('HTTP/1.1 503 Service Unavailable')
        ->and($head)->toStartWith('HTTP/1.1 200 OK')->toEndWith("\r\n\r\n")
        ->and($missing)->toStartWith('HTTP/1.1 404 Not Found')
        ->and($bad)->toStartWith('HTTP/1.1 400 Bad Request');
});

it('answers only health when no metrics registry is given', function () {
    $loop = new StreamSelectLoop;
    $endpoint = new HttpEndpoint('127.0.0.1', 0, fn () => [true, []]);
    $endpoint->start($loop);

    $response = http_request($loop, $endpoint, "GET /metrics HTTP/1.1\r\n\r\n");
    $endpoint->stop();

    expect($response)->toStartWith('HTTP/1.1 404 Not Found');
});

it('drops requests that are too large', function () {
    $loop = new StreamSelectLoop;
    $endpoint = new HttpEndpoint('127.0.0.1', 0, fn () => [true, []]);
    $endpoint->start($loop);

    $response = http_request($loop, $endpoint, 'GET /health HTTP/1.1'."\r\nX-Pad: ".str_repeat('a', 9000)."\r\n\r\n");
    $endpoint->stop();

    expect($response)->toStartWith('HTTP/1.1 431');
});
