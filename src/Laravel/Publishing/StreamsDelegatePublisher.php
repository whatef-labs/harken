<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Publishing;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use React\Promise\PromiseInterface;
use Redis;
use RuntimeException;
use Throwable;
use Whatef\Harken\Laravel\StoreConfig;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Outbox\OutboxMessage;
use Whatef\Harken\Runtime\Publisher;

use function React\Promise\resolve;

/**
 * Publishing from outside the runtime: appends the message to the outbox
 * Redis stream (XADD) on the application's Redis connection, for a running
 * runtime to send.
 *
 * The call is synchronous. It returns an already resolved promise holding
 * the stream entry id once Redis has stored the entry, and throws
 * OutboxUnavailable when it could not, so a failure is never silent in
 * code that ignores the promise.
 */
final readonly class StreamsDelegatePublisher implements Publisher
{
    /**
     * @param  string  $stream  Stream key without the Redis key prefix.
     */
    public function __construct(
        private RedisFactory $redis,
        private StoreConfig $stores,
        public string $redisConnection,
        public string $stream,
    ) {}

    /**
     * @return PromiseInterface<string>
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null): PromiseInterface
    {
        $message = new OutboxMessage($topic, $payload, $qos, $retain, null, $properties ?? new Properties);

        try {
            // Raw commands skip the client's own key prefix; add the same
            // prefix the runtime reads with.
            $command = ['XADD', $this->stores->redis($this->redisConnection)->prefix.$this->stream, '*'];

            foreach ($message->toFields() as $field => $value) {
                $command[] = $field;
                $command[] = $value;
            }

            $id = $this->raw($this->redis->connection($this->redisConnection), $command);
        } catch (Throwable $e) {
            throw OutboxUnavailable::because($topic, $e);
        }

        if (! is_string($id) || $id === '') {
            throw OutboxUnavailable::because($topic);
        }

        return resolve($id);
    }

    /**
     * @param  list<string>  $command
     */
    private function raw(mixed $connection, array $command): mixed
    {
        $client = $connection instanceof Connection ? $connection->client() : null;

        return match (true) {
            $client instanceof Redis => $client->rawCommand(...$command),
            is_object($client) && method_exists($client, 'executeRaw') => $client->executeRaw($command),
            default => throw new RuntimeException('Unsupported Redis client; use phpredis or predis.'),
        };
    }
}
