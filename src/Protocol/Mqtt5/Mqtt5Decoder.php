<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Mqtt5;

use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\Wire\Framer;

final class Mqtt5Decoder implements Decoder
{
    public function __construct(
        private readonly Mqtt5Codec $codec,
        private readonly Framer $framer,
    ) {}

    public function push(string $bytes): array
    {
        $packets = [];

        foreach ($this->framer->push($bytes) as $frame) {
            $packets[] = $this->codec->decode($frame);
        }

        return $packets;
    }
}
