<?php

declare(strict_types=1);

namespace Whatef\Harken\Tests\Support;

use RuntimeException;
use SplObjectStorage;
use Whatef\Harken\Protocol\Codec;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Protocol\Packet\SubAck;
use Whatef\Harken\Protocol\Packet\Subscribe;
use Whatef\Harken\Protocol\ReasonCode;
use Whatef\Harken\Transport\InMemoryConnection;
use Whatef\Harken\Transport\InMemoryTransport;

/**
 * Drives the broker side of InMemoryTransport connections packet by packet.
 */
final class FakeBroker
{
    public readonly InMemoryTransport $transport;

    /** @var SplObjectStorage<InMemoryConnection, Decoder> */
    private SplObjectStorage $decoders;

    /** @var list<Packet> */
    private array $inbox = [];

    private ?InMemoryConnection $current = null;

    public function __construct(private readonly Codec $codec)
    {
        $this->transport = new InMemoryTransport;
        $this->decoders = new SplObjectStorage;
    }

    /**
     * Packets the client sent on the current connection since the last call.
     *
     * @return list<Packet>
     */
    public function received(): array
    {
        $this->read();
        $packets = $this->inbox;
        $this->inbox = [];

        return $packets;
    }

    /**
     * The next packet of the given class sent on the current connection.
     * Earlier packets of other classes are discarded.
     *
     * @template T of Packet
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function expect(string $class): Packet
    {
        $this->read();

        while ($this->inbox !== []) {
            $packet = array_shift($this->inbox);

            if ($packet instanceof $class) {
                return $packet;
            }
        }

        throw new RuntimeException("The client sent no {$class}.");
    }

    public function send(Packet ...$packets): void
    {
        foreach ($packets as $packet) {
            $this->peer()->write($this->codec->encode($packet));
        }
    }

    public function sendRaw(string $bytes): void
    {
        $this->peer()->write($bytes);
    }

    /**
     * Reads CONNECT and answers it.
     */
    public function accept(ConnAck $ack = new ConnAck): Connect
    {
        $connect = $this->expect(Connect::class);
        $this->send($ack);

        return $connect;
    }

    /**
     * Reads SUBSCRIBE and grants every filter (or answers with $codes).
     *
     * @param  list<ReasonCode>|null  $codes
     */
    public function grant(?array $codes = null): Subscribe
    {
        $subscribe = $this->expect(Subscribe::class);
        $granted = $codes ?? array_map(
            static fn (int $qos): ReasonCode => $qos === 1 ? ReasonCode::GrantedQos1 : ReasonCode::Success,
            array_values($subscribe->filters),
        );
        /** @var non-empty-list<ReasonCode> $granted */
        $this->send(new SubAck($subscribe->packetId, $granted));

        return $subscribe;
    }

    public function connections(): int
    {
        return $this->transport->connectionCount();
    }

    public function drop(): void
    {
        $this->peer()->close();
    }

    public function isOpen(): bool
    {
        return $this->peer()->isWritable();
    }

    private function peer(): InMemoryConnection
    {
        $peer = $this->transport->peer();

        if ($peer !== $this->current) {
            $this->current = $peer;
            $this->inbox = [];
        }

        return $peer;
    }

    private function read(): void
    {
        $peer = $this->peer();

        if (! $this->decoders->offsetExists($peer)) {
            $this->decoders[$peer] = $this->codec->decoder();
        }

        $bytes = $peer->drain();

        if ($bytes !== '') {
            array_push($this->inbox, ...$this->decoders[$peer]->push($bytes));
        }
    }
}
