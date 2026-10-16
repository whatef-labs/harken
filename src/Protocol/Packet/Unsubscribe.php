<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

final readonly class Unsubscribe implements Packet
{
    /**
     * @param  int<1, 65535>  $packetId
     * @param  non-empty-list<string>  $filters
     * @param  array<string, string|list<string>>  $userProperties  MQTT 5 only.
     */
    public function __construct(
        public int $packetId,
        public array $filters,
        public array $userProperties = [],
    ) {}
}
