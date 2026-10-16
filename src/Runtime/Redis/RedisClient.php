<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Redis;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use RuntimeException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * One asynchronous Redis connection of the runtime (clue/redis-react).
 *
 * It connects on first use and again on the next command after the
 * connection was lost. It authenticates with a username when one is set
 * (Redis 6 ACL users), then selects the database. DNS is skipped for IP
 * hosts. Commands on one client run in order; a blocking command (such as
 * XREADGROUP BLOCK) holds back the commands after it, so give it a client
 * of its own.
 */
final class RedisClient
{
    private readonly Factory $factory;

    private ?Client $client = null;

    /** @var PromiseInterface<Client>|null */
    private ?PromiseInterface $connecting = null;

    private bool $closed = false;

    public function __construct(public readonly RedisEndpoint $endpoint, LoopInterface $loop)
    {
        $this->factory = new Factory($loop, new Connector([
            'timeout' => $endpoint->connectTimeout,
            'dns' => ! $endpoint->hostIsIp(),
        ], $loop));
    }

    /**
     * @return PromiseInterface<mixed>
     */
    public function call(string $command, string|int|float ...$args): PromiseInterface
    {
        if ($this->closed) {
            return reject(new RuntimeException('The Redis client is closed.'));
        }

        $args = array_map(static fn (string|int|float $arg): string => (string) $arg, array_values($args));

        return $this->connection()->then(static fn (Client $client): PromiseInterface => $client->__call($command, $args));
    }

    public function close(): void
    {
        $this->closed = true;
        $client = $this->client;
        $this->client = null;
        $client?->close();
    }

    /**
     * Closes once the replies to the commands already sent have arrived.
     */
    public function end(): void
    {
        $this->closed = true;
        $client = $this->client;
        $this->client = null;
        $client?->end();
    }

    /**
     * @return PromiseInterface<Client>
     */
    private function connection(): PromiseInterface
    {
        if ($this->client !== null) {
            return resolve($this->client);
        }

        if ($this->connecting !== null) {
            return $this->connecting;
        }

        $endpoint = $this->endpoint;

        return $this->connecting = $this->factory->createClient($endpoint->uri())->then(
            function (Client $client) use ($endpoint): PromiseInterface {
                $ready = resolve(null);

                if ($endpoint->password !== null) {
                    $credentials = $endpoint->username === null ? [$endpoint->password] : [$endpoint->username, $endpoint->password];
                    $ready = $client->__call('auth', $credentials);
                }

                if ($endpoint->database !== 0) {
                    $ready = $ready->then(static fn (): PromiseInterface => $client->__call('select', [(string) $endpoint->database]));
                }

                return $ready->then(function () use ($client): Client {
                    $this->connecting = null;

                    if ($this->closed) {
                        $client->close();

                        throw new RuntimeException('The Redis client is closed.');
                    }

                    $this->client = $client;
                    $client->on('close', function () use ($client): void {
                        if ($this->client === $client) {
                            $this->client = null;
                        }
                    });

                    return $client;
                }, function (Throwable $e) use ($client): never {
                    $this->connecting = null;
                    $client->close();

                    throw $e;
                });
            },
            function (Throwable $e): never {
                $this->connecting = null;

                throw $e;
            },
        );
    }
}
