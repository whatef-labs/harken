<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Mqtt5;

/**
 * @internal
 */
enum PropertyType
{
    case Byte;
    case TwoByteInteger;
    case FourByteInteger;
    case VariableByteInteger;
    case Utf8String;
    case BinaryData;
    case Utf8StringPair;
}
