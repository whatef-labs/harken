<?php

declare(strict_types=1);

/*
 * Minimal MQTT 3.1.1 client used only by the spikes: one connection,
 * QoS 0/1, explicit control over when inbound PUBACKs are sent.
 * It deliberately has no reconnect, keepalive timer or resubscription;
 * those belong to the real Connection class.
 */

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Protocol\Packet\Disconnect;
use Whatef\Harken\Protocol\Packet\Packet;
use Whatef\Harken\Protocol\Packet\PingReq;
use Whatef\Harken\Protocol\Packet\PingResp;
use Whatef\Harken\Protocol\Packet\PubAck;
use Whatef\Harken\Protocol\Packet\Publish;
use Whatef\Harken\Protocol\Packet\SubAck;
use Whatef\Harken\Protocol\Packet\Subscribe;
use Whatef\Harken\Protocol\PacketIdPool;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\ReactSocketTransport;

use function React\Promise\reject;
use function React\Promise\resolve;

final class MiniClient
{
    private BinsoulCodec $codec;

    private Decoder $decoder;

    private PacketIdPool $ids;

    private ?Deferred $connAck = null;

    /** @var array<int, Deferred> */
    private array $pubAcks = [];

    /** @var array<int, Deferred> */
    private array $subAcks = [];

    private ?Deferred $pingResp = null;

    private bool $closed = false;

    /** @var (Closure(Publish, self): void)|null */
    public ?Closure $onMessage = null;

    /** @var (Closure(): void)|null */
    public ?Closure $onClose = null;

    /** Send PUBACK for inbound QoS 1 right after onMessage returns. */
    public bool $autoAck = true;

    private function __construct(private ConnectionInterface $socket, public readonly string $clientId)
    {
        $this->codec = new BinsoulCodec;
        $this->decoder = $this->codec->decoder();
        $this->ids = new PacketIdPool;

        $socket->on('data', function (string $data): void {
            foreach ($this->decoder->push($data) as $packet) {
                $this->receive($packet);
            }
        });
        $socket->on('close', function (): void {
            $this->closed = true;
            $this->connAck?->reject(new RuntimeException('closed before CONNACK'));
            foreach ($this->pubAcks as $d) {
                $d->reject(new RuntimeException('closed before PUBACK'));
            }
            if ($this->onClose !== null) {
                ($this->onClose)();
            }
        });
    }

    /**
     * @return PromiseInterface<self>
     */
    public static function connect(
        Endpoint $endpoint,
        string $clientId,
        bool $cleanSession = true,
        int $keepAlive = 60,
        ?string $username = null,
        ?string $password = null,
    ): PromiseInterface {
        return (new ReactSocketTransport)->connect($endpoint)->then(function (ConnectionInterface $socket) use ($clientId, $cleanSession, $keepAlive, $username, $password) {
            $client = new self($socket, $clientId);
            $client->connAck = new Deferred;
            $client->send(new Connect($clientId, $keepAlive, $cleanSession, $username, $password));

            return $client->connAck->promise()->then(function (ConnAck $ack) use ($client) {
                if (! $ack->accepted()) {
                    $client->socket->close();

                    return reject(new RuntimeException("CONNACK refused with code {$ack->returnCode}"));
                }
                $client->sessionPresent = $ack->sessionPresent;

                return $client;
            });
        });
    }

    public bool $sessionPresent = false;

    /**
     * @param  non-empty-array<string, int>  $filters
     * @return PromiseInterface<SubAck>
     */
    public function subscribe(array $filters): PromiseInterface
    {
        $id = $this->ids->acquire();
        $this->subAcks[$id] = new Deferred;
        $this->send(new Subscribe($id, $filters));

        return $this->subAcks[$id]->promise();
    }

    /**
     * QoS 0 resolves after the write, QoS 1 after the PUBACK.
     *
     * @return PromiseInterface<null>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): PromiseInterface
    {
        if ($this->closed) {
            return reject(new RuntimeException('connection closed'));
        }

        if ($qos === 0) {
            $this->send(new Publish($topic, $payload, 0, $retain));

            return resolve(null);
        }

        $id = $this->ids->acquire();
        $this->pubAcks[$id] = new Deferred;
        $this->send(new Publish($topic, $payload, 1, $retain, false, $id));

        return $this->pubAcks[$id]->promise();
    }

    public function ack(int $packetId): void
    {
        $this->send(new PubAck($packetId));
    }

    /**
     * @return PromiseInterface<null>
     */
    public function ping(): PromiseInterface
    {
        $this->pingResp = new Deferred;
        $this->send(new PingReq);

        return $this->pingResp->promise();
    }

    public function disconnect(): void
    {
        if (! $this->closed) {
            $this->send(new Disconnect);
            $this->socket->end();
        }
    }

    /** Drops the TCP connection without DISCONNECT, like a crashed process. */
    public function kill(): void
    {
        $this->socket->close();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function send(Packet $packet): void
    {
        $this->socket->write($this->codec->encode($packet));
    }

    private function receive(Packet $packet): void
    {
        switch (true) {
            case $packet instanceof ConnAck:
                $this->connAck?->resolve($packet);
                $this->connAck = null;
                break;
            case $packet instanceof PubAck:
                $this->ids->release($packet->packetId);
                ($this->pubAcks[$packet->packetId] ?? null)?->resolve(null);
                unset($this->pubAcks[$packet->packetId]);
                break;
            case $packet instanceof SubAck:
                $this->ids->release($packet->packetId);
                ($this->subAcks[$packet->packetId] ?? null)?->resolve($packet);
                unset($this->subAcks[$packet->packetId]);
                break;
            case $packet instanceof PingResp:
                $this->pingResp?->resolve(null);
                $this->pingResp = null;
                break;
            case $packet instanceof Publish:
                if ($this->onMessage !== null) {
                    ($this->onMessage)($packet, $this);
                }
                if ($packet->qos === 1 && $this->autoAck && $packet->packetId !== null) {
                    $this->ack($packet->packetId);
                }
                break;
        }
    }
}
