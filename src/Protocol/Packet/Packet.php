<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Packet;

/**
 * Codec-independent MQTT control packet.
 *
 * The runtime only ever sees these value objects, so a different codec
 * (for example an MQTT 5 one) can be dropped in behind the Codec interface.
 */
interface Packet {}
