<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Wire;

/**
 * One complete control packet cut from the inbound byte stream.
 *
 * @internal
 */
final readonly class Frame
{
    /**
     * @param  int<1, 15>  $type
     * @param  int<0, 15>  $flags  Low nibble of the first byte.
     * @param  string  $body  Variable header and payload.
     * @param  string  $header  Fixed header: first byte and remaining length.
     */
    public function __construct(
        public int $type,
        public int $flags,
        public string $body,
        public string $header,
    ) {}

    public function bytes(): string
    {
        return $this->header.$this->body;
    }
}
