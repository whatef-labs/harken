<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Binsoul;

use BinSoul\Net\Mqtt\StreamParser;
use Throwable;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\ProtocolException;
use Whatef\Harken\Protocol\Wire\Framer;

/**
 * Frames the stream itself, so the size limit applies before anything is
 * buffered, and hands binsoul one complete packet at a time.
 */
final class BinsoulDecoder implements Decoder
{
    private readonly StreamParser $parser;

    public function __construct(
        private readonly BinsoulCodec $codec,
        private readonly Framer $framer,
    ) {
        $this->parser = new StreamParser;
        $this->parser->onError(static function (Throwable $e): void {
            throw new ProtocolException($e->getMessage(), previous: $e);
        });
    }

    public function push(string $bytes): array
    {
        $packets = [];

        foreach ($this->framer->push($bytes) as $frame) {
            foreach ($this->parser->push($frame->bytes()) as $packet) {
                $packets[] = $this->codec->fromBinsoul($packet);
            }
        }

        return $packets;
    }
}
