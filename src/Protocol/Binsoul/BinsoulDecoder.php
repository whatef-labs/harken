<?php

declare(strict_types=1);

namespace Whatef\Harken\Protocol\Binsoul;

use BinSoul\Net\Mqtt\StreamParser;
use Throwable;
use Whatef\Harken\Protocol\Decoder;
use Whatef\Harken\Protocol\ProtocolException;

final class BinsoulDecoder implements Decoder
{
    private readonly StreamParser $parser;

    public function __construct(private readonly BinsoulCodec $codec)
    {
        $this->parser = new StreamParser;
        $this->parser->onError(static function (Throwable $e): void {
            throw new ProtocolException($e->getMessage(), previous: $e);
        });
    }

    public function push(string $bytes): array
    {
        $packets = [];

        foreach ($this->parser->push($bytes) as $packet) {
            $packets[] = $this->codec->fromBinsoul($packet);
        }

        return $packets;
    }
}
