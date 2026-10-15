<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Message;
use Whatef\Harken\Runtime\Routing\Dispatcher;
use Whatef\Harken\Runtime\Routing\DispatchObserver;
use Whatef\Harken\Runtime\Routing\HandlerInvoker;
use Whatef\Harken\Runtime\Routing\Route;
use Whatef\Harken\Runtime\Routing\Router;

function recordingObserver(): DispatchObserver
{
    return new class implements DispatchObserver
    {
        /** @var list<array{string, mixed}> */
        public array $events = [];

        public function unmatched(Message $message): void
        {
            $this->events[] = ['unmatched', $message->topic];
        }

        public function handled(Route $route, Message $message, float $milliseconds): void
        {
            $this->events[] = ['handled', $message->topic];
        }

        public function slow(Route $route, Message $message, float $milliseconds): void
        {
            $this->events[] = ['slow', $message->topic];
        }

        public function failed(Route $route, Message $message, Throwable $exception, float $milliseconds): void
        {
            $this->events[] = ['failed', $exception->getMessage()];
        }
    };
}

function callableInvoker(): HandlerInvoker
{
    return new class implements HandlerInvoker
    {
        public function invoke(Route $route, Message $message): void
        {
            ($route->getHandler())($message);
        }
    };
}

it('passes route parameters to the handler', function () {
    $received = null;
    $router = new Router;
    $router->add(new Route('sensors/{id}/status', function (Message $m) use (&$received) {
        $received = $m;
    }));
    $observer = recordingObserver();

    $handled = (new Dispatcher($router, callableInvoker(), $observer))
        ->dispatch(new Message('sensors/abc/status', "\x00\xffraw", 1, true));

    expect($handled)->toBeTrue()
        ->and($received)->toBeInstanceOf(Message::class)
        ->and($received->param('id'))->toBe('abc')
        ->and($received->payload)->toBe("\x00\xffraw")
        ->and($received->qos)->toBe(1)
        ->and($received->retain)->toBeTrue()
        ->and($observer->events)->toBe([['handled', 'sensors/abc/status']]);
});

it('reports unmatched topics and carries on', function () {
    $observer = recordingObserver();

    $handled = (new Dispatcher(new Router, callableInvoker(), $observer))->dispatch(new Message('nobody/home', ''));

    expect($handled)->toBeFalse()
        ->and($observer->events)->toBe([['unmatched', 'nobody/home']]);
});

it('isolates handler exceptions', function () {
    $router = new Router;
    $router->add(new Route('boom', fn () => throw new RuntimeException('kaboom')));
    $observer = recordingObserver();

    $handled = (new Dispatcher($router, callableInvoker(), $observer))->dispatch(new Message('boom', ''));

    expect($handled)->toBeTrue()
        ->and($observer->events)->toBe([['failed', 'kaboom']]);
});

it('reports handlers slower than the threshold', function () {
    $router = new Router;
    $router->add(new Route('slow', fn () => usleep(2000)));
    $observer = recordingObserver();

    (new Dispatcher($router, callableInvoker(), $observer, slowThresholdMs: 1.0))->dispatch(new Message('slow', ''));

    expect($observer->events)->toBe([['handled', 'slow'], ['slow', 'slow']]);
});
