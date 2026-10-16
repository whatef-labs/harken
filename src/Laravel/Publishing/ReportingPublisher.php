<?php

declare(strict_types=1);

namespace Whatef\Harken\Laravel\Publishing;

use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Throwable;
use Whatef\Harken\Protocol\Properties;
use Whatef\Harken\Runtime\Publisher;

/**
 * Publishing inside the runtime: handlers and timers often ignore the
 * promise Harken::publish() returns. A failure (a refused publish, a
 * connection that stopped) is logged here instead of surfacing as an
 * unhandled promise rejection. The caller still receives the original
 * promise and can handle the failure itself.
 */
final readonly class ReportingPublisher implements Publisher
{
    public function __construct(
        private Publisher $publisher,
        private LoggerInterface $logger,
    ) {}

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false, ?Properties $properties = null, bool $urgent = false): PromiseInterface
    {
        $promise = $this->publisher->publish($topic, $payload, $qos, $retain, $properties, $urgent);

        $promise->then(null, function (Throwable $e) use ($topic): void {
            $this->logger->warning("Harken could not publish to [{$topic}]: {$e->getMessage()}", ['exception' => $e::class]);
        });

        return $promise;
    }
}
