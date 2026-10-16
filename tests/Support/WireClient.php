<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Support;

use RuntimeException;
use Whatef\Harken\Protocol\Codec;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Packet;

/**
 * A blocking, packet-level MQTT client for integration tests. It sends
 * exactly the packets a test gives it and acknowledges nothing on its own,
 * so tests can observe broker behaviour packet by packet.
 */
final class WireClient
{
    /** @var list<Packet> */
    private array $inbox = [];

    private readonly Decoder $decoder;

    private bool $closed = false;

    /**
     * @param  resource  $socket
     */
    private function __construct(private $socket, private readonly Codec $codec)
    {
        $this->decoder = $codec->decoder();
    }

    /**
     * @return array{self, ConnAck}
     */
    public static function connect(string $host, int $port, Codec $codec, Connect $connect): array
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 5.0);

        if ($socket === false) {
            throw new RuntimeException("Cannot reach {$host}:{$port}: {$error}");
        }

        $client = new self($socket, $codec);
        $client->send($connect);

        return [$client, $client->expect(ConnAck::class)];
    }

    public function send(Packet $packet): void
    {
        $bytes = $this->codec->encode($packet);

        while ($bytes !== '') {
            $written = @fwrite($this->socket, $bytes);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Write failed: the connection is closed.');
            }

            $bytes = substr($bytes, $written);
        }
    }

    /**
     * Waits for the next packet of the given class. Other packets stay queued.
     *
     * @template T of Packet
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function expect(string $class, float $timeout = 5.0): Packet
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            foreach ($this->inbox as $i => $packet) {
                if ($packet instanceof $class) {
                    array_splice($this->inbox, $i, 1);

                    return $packet;
                }
            }

            if (! $this->read($deadline)) {
                throw new RuntimeException(sprintf('No %s within %.1f s%s.', $class, $timeout, $this->closed ? ' (connection closed)' : ''));
            }
        }
    }

    /**
     * Every packet of the given class that arrives within the time window.
     *
     * @template T of Packet
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function collect(string $class, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;

        while ($this->read($deadline)) {
            // keep reading until the window closes
        }

        $matched = array_values(array_filter($this->inbox, static fn (Packet $p): bool => $p instanceof $class));
        $this->inbox = array_values(array_filter($this->inbox, static fn (Packet $p): bool => ! $p instanceof $class));

        return $matched;
    }

    /**
     * True when the broker closes the connection within the timeout.
     */
    public function closesWithin(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        while (! $this->closed && $this->read($deadline)) {
            // drain until EOF or timeout
        }

        return $this->closed;
    }

    /**
     * Drops the TCP connection without DISCONNECT, like a crashed process.
     */
    public function drop(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->closed = true;
    }

    private function read(float $deadline): bool
    {
        if ($this->closed) {
            return false;
        }

        $wait = $deadline - microtime(true);

        if ($wait <= 0) {
            return false;
        }

        $read = [$this->socket];
        $write = $except = null;
        $seconds = (int) $wait;

        if (@stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1e6)) < 1) {
            return false;
        }

        $bytes = fread($this->socket, 65536);

        if ($bytes === false || $bytes === '') {
            $this->closed = feof($this->socket) || $bytes === false;

            return ! $this->closed;
        }

        array_push($this->inbox, ...$this->decoder->push($bytes));

        return true;
    }
}
