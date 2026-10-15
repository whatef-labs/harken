<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol;

enum ProtocolVersion: string
{
    case V3_1_1 = '3.1.1';
    case V5_0 = '5.0';
}
