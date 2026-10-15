<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

use Evenement\EventEmitter;
use React\Socket\ConnectionInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

/**
 * One end of an in-memory duplex stream. Data written on one end is emitted
 * synchronously as a `data` event on the other end.
 */
final class InMemoryConnection extends EventEmitter implements ConnectionInterface
{
    private ?self $other = null;

    private bool $closed = false;

    private string $received = '';

    /**
     * @return array{self, self}
     */
    public static function pair(): array
    {
        $a = new self;
        $b = new self;
        $a->other = $b;
        $b->other = $a;

        return [$a, $b];
    }

    /**
     * Returns and clears everything this end has received so far.
     */
    public function drain(): string
    {
        $data = $this->received;
        $this->received = '';

        return $data;
    }

    public function getRemoteAddress(): string
    {
        return 'memory://remote';
    }

    public function getLocalAddress(): string
    {
        return 'memory://local';
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function isWritable(): bool
    {
        return ! $this->closed;
    }

    public function pause(): void {}

    public function resume(): void {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return Util::pipe($this, $dest, $options);
    }

    public function write($data): bool
    {
        if ($this->closed || $this->other === null) {
            return false;
        }

        $this->other->receive((string) $data);

        return true;
    }

    public function end($data = null): void
    {
        if ($data !== null) {
            $this->write($data);
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->emit('close');
        $this->removeAllListeners();
        $this->other?->close();
    }

    private function receive(string $data): void
    {
        $this->received .= $data;
        $this->emit('data', [$data]);
    }
}
