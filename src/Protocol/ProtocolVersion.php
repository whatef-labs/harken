<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Mqtt5\Mqtt5Codec;

/**
 * The MQTT version a connection speaks. The client chooses it in CONNECT;
 * the broker accepts or refuses it. Clients of different versions can share
 * a broker.
 */
enum ProtocolVersion: string
{
    case V3_1_1 = '3.1.1';
    case V5_0 = '5.0';

    public function codec(): Codec
    {
        return match ($this) {
            self::V3_1_1 => new BinsoulCodec,
            self::V5_0 => new Mqtt5Codec,
        };
    }
}
