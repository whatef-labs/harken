<?php

declare(strict_types=1);

namespace Whatef\Harken\Observability;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use SplObjectStorage;
use Throwable;
use Whatef\Harken\Runtime\Sources\Source;

/**
 * A small HTTP endpoint on the runtime's loop, for scrapers and probes:
 *
 * - `GET /health` answers 200 with the runtime status as JSON while it is
 *   healthy, 503 otherwise;
 * - `GET /metrics` answers the metrics in the Prometheus text format, when
 *   a registry is given.
 *
 * It reads one request per connection (no keep-alive) and closes slow or
 * oversized requests, so a misbehaving client cannot hold the loop.
 */
final class HttpEndpoint implements Source
{
    private const int MAX_REQUEST_BYTES = 8192;

    private const float REQUEST_TIMEOUT = 5.0;

    private ?SocketServer $server = null;

    private ?LoopInterface $loop = null;

    /** @var SplObjectStorage<ConnectionInterface, TimerInterface> */
    private SplObjectStorage $clients;

    /** @var Closure(): array{bool, array<string, mixed>} */
    private readonly Closure $health;

    /**
     * @param  callable(): array{bool, array<string, mixed>}  $health  Whether the runtime is healthy, and its status.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        callable $health,
        private readonly ?MetricsRegistry $metrics = null,
    ) {
        $this->health = $health(...);
        $this->clients = new SplObjectStorage;
    }

    public function name(): string
    {
        return "http://{$this->address()}";
    }

    /**
     * The address the endpoint listens on, with the actual port once started.
     */
    public function address(): string
    {
        $address = $this->server?->getAddress();

        return $address === null ? "{$this->host}:{$this->port}" : (string) preg_replace('#^tcp://#', '', $address);
    }

    public function start(LoopInterface $loop): void
    {
        if ($this->server !== null) {
            return;
        }

        $this->loop = $loop;
        $this->server = new SocketServer("{$this->host}:{$this->port}", [], $loop);
        $this->server->on('connection', $this->accept(...));
    }

    public function stop(): void
    {
        $this->server?->close();
        $this->server = null;

        foreach (iterator_to_array($this->clients, false) as $client) {
            $client->close();
        }
    }

    private function accept(ConnectionInterface $client): void
    {
        $loop = $this->loop;

        if ($loop === null) {
            $client->close();

            return;
        }

        $buffer = '';
        $this->clients[$client] = $loop->addTimer(self::REQUEST_TIMEOUT, static fn () => $client->close());

        $client->on('data', function (string $data) use ($client, &$buffer): void {
            $buffer .= $data;

            if (strlen($buffer) > self::MAX_REQUEST_BYTES) {
                $this->respond($client, 431, 'text/plain', "Request too large.\n");

                return;
            }

            if (str_contains($buffer, "\r\n\r\n")) {
                $this->handle($client, strstr($buffer, "\r\n", true) ?: '');
            }
        });

        $client->on('close', function () use ($client): void {
            if ($this->clients->offsetExists($client)) {
                $this->loop?->cancelTimer($this->clients[$client]);
                $this->clients->offsetUnset($client);
            }
        });
    }

    private function handle(ConnectionInterface $client, string $requestLine): void
    {
        if (preg_match('#^(GET|HEAD) (\S+) HTTP/1\.[01]$#', $requestLine, $m) !== 1) {
            $this->respond($client, 400, 'text/plain', "Bad request.\n");

            return;
        }

        $path = (string) parse_url($m[2], PHP_URL_PATH);
        $head = $m[1] === 'HEAD';

        try {
            match ($path) {
                '/health' => $this->healthResponse($client, $head),
                '/metrics' => $this->metrics === null
                    ? $this->respond($client, 404, 'text/plain', "Metrics are not enabled.\n", $head)
                    : $this->respond($client, 200, PrometheusFormat::CONTENT_TYPE, PrometheusFormat::render($this->metrics), $head),
                default => $this->respond($client, 404, 'text/plain', "Not found.\n", $head),
            };
        } catch (Throwable) {
            $this->respond($client, 500, 'text/plain', "Internal error.\n", $head);
        }
    }

    private function healthResponse(ConnectionInterface $client, bool $head): void
    {
        [$healthy, $status] = ($this->health)();

        $this->respond(
            $client,
            $healthy ? 200 : 503,
            'application/json',
            json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n",
            $head,
        );
    }

    private function respond(ConnectionInterface $client, int $status, string $type, string $body, bool $head = false): void
    {
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            431 => 'Request Header Fields Too Large',
            503 => 'Service Unavailable',
            default => 'Internal Server Error',
        };

        $client->end(sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %d\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n%s",
            $status,
            $reason,
            $type,
            strlen($body),
            $head ? '' : $body,
        ));
    }
}
