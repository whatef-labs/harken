<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

/**
 * Hands out MQTT packet identifiers (1..65535) that are not currently in flight.
 */
final class PacketIdPool
{
    /** @var array<int, true> */
    private array $inUse = [];

    /** @var int<1, 65535> */
    private int $next = 1;

    /**
     * @return int<1, 65535>
     *
     * @throws ProtocolException when all identifiers are in use.
     */
    public function acquire(): int
    {
        if (count($this->inUse) >= 65535) {
            throw new ProtocolException('All 65535 packet identifiers are in flight.');
        }

        while (isset($this->inUse[$this->next])) {
            $this->next = $this->next === 65535 ? 1 : $this->next + 1;
        }

        $id = $this->next;
        $this->inUse[$id] = true;
        $this->next = $id === 65535 ? 1 : $id + 1;

        return $id;
    }

    public function release(int $id): void
    {
        unset($this->inUse[$id]);
    }

    public function inUse(): int
    {
        return count($this->inUse);
    }
}
