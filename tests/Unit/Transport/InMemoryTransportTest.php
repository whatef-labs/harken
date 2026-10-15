<?php

declare(strict_types=1);

use React\Socket\ConnectionInterface;
use Whatef\Harken\Protocol\Binsoul\BinsoulCodec;
use Whatef\Harken\Protocol\Packet\ConnAck;
use Whatef\Harken\Protocol\Packet\Connect;
use Whatef\Harken\Transport\Endpoint;
use Whatef\Harken\Transport\InMemoryTransport;

use function React\Async\await;

it('links the client end with a broker end the test can drive', function () {
    $codec = new BinsoulCodec;
    $transport = new InMemoryTransport;
    /** @var ConnectionInterface $client */
    $client = await($transport->connect(new Endpoint('broker.test')));
    $received = '';
    $client->on('data', function (string $data) use (&$received) {
        $received .= $data;
    });

    $client->write($codec->encode(new Connect('c1')));
    $peer = $transport->peer();
    $peer->write($codec->encode(new ConnAck(0)));

    expect($codec->decoder()->push($peer->drain()))->toEqual([new Connect('c1')])
        ->and($codec->decoder()->push($received))->toEqual([new ConnAck(0)])
        ->and($transport->endpoints()[0]->uri())->toBe('tcp://broker.test:1883');
});

it('closes both ends together', function () {
    $transport = new InMemoryTransport;
    $client = await($transport->connect(new Endpoint('broker.test')));
    $closed = false;
    $client->on('close', function () use (&$closed) {
        $closed = true;
    });

    $transport->peer()->close();

    expect($closed)->toBeTrue()->and($client->isWritable())->toBeFalse();
});

it('can fail the next connection attempt', function () {
    $transport = new InMemoryTransport;
    $transport->failNextConnect(new RuntimeException('refused'));

    await($transport->connect(new Endpoint('broker.test')));
})->throws(RuntimeException::class, 'refused');
