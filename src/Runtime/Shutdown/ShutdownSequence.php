<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Shutdown;

use Closure;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Runs shutdown steps one after another within a grace period.
 *
 * Each step gets the time left of the grace period; a step that has not
 * finished by then is abandoned and the next one starts, so the last steps
 * (DISCONNECT, closing sockets) always run. A step that throws or rejects
 * is reported and skipped.
 */
final class ShutdownSequence
{
    public function __construct(public readonly float $graceSeconds = 10.0) {}

    /**
     * @param  array<string, callable(float $secondsLeft): PromiseInterface<mixed>>  $steps  Name => step, in order.
     * @param  (callable(string $step, ?Throwable $error): void)|null  $onAbandoned  A step timed out (error null) or failed.
     * @return PromiseInterface<null>
     */
    public function run(LoopInterface $loop, array $steps, ?callable $onAbandoned = null): PromiseInterface
    {
        $deadline = hrtime(true) / 1e9 + $this->graceSeconds;
        $done = new Deferred;
        $queue = $steps;
        $report = $onAbandoned === null ? null : $onAbandoned(...);

        $next = null;
        $next = static function () use (&$next, &$queue, $loop, $deadline, $done, $report): void {
            if ($queue === []) {
                $done->resolve(null);

                return;
            }

            $name = array_key_first($queue);
            $step = $queue[$name];
            unset($queue[$name]);

            $left = max(0.0, $deadline - hrtime(true) / 1e9);
            $settled = false;
            /** @var Closure(): void $advance */
            $advance = $next;
            $timer = $loop->addTimer($left, static function () use (&$settled, $advance, $report, $name): void {
                if (! $settled) {
                    $settled = true;
                    $report?->__invoke((string) $name, null);
                    $advance();
                }
            });

            $finish = static function (?Throwable $error) use (&$settled, $timer, $loop, $advance, $report, $name): void {
                if ($settled) {
                    return;
                }

                $settled = true;
                $loop->cancelTimer($timer);

                if ($error !== null) {
                    $report?->__invoke((string) $name, $error);
                }

                $advance();
            };

            try {
                $step($left)->then(static fn () => $finish(null), static fn (Throwable $e) => $finish($e));
            } catch (Throwable $e) {
                $finish($e);
            }
        };

        $next();

        return $done->promise();
    }
}
