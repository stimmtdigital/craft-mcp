<?php

declare(strict_types=1);

use Psr\EventDispatcher\StoppableEventInterface;
use stimmt\craft\Mcp\support\EventDispatcher;

/**
 * Minimal stoppable event for exercising the dispatcher's PSR-14
 * StoppableEventInterface support without pulling in an SDK event.
 */
final class StoppableTestEvent implements StoppableEventInterface {
    private bool $stopped = false;

    public function stop(): void {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool {
        return $this->stopped;
    }
}

describe('EventDispatcher', function () {
    it('dispatches to listeners registered for the exact event class', function () {
        $dispatcher = new EventDispatcher();
        $received = [];

        $dispatcher->addListener(stdClass::class, function (object $event) use (&$received): void {
            $received[] = $event;
        });

        $event = new stdClass();
        $result = $dispatcher->dispatch($event);

        expect($received)->toBe([$event])
            ->and($result)->toBe($event);
    });

    it('calls multiple listeners for the same event in registration order', function () {
        $dispatcher = new EventDispatcher();
        $order = [];

        $dispatcher->addListener(stdClass::class, function () use (&$order): void {
            $order[] = 'first';
        });
        $dispatcher->addListener(stdClass::class, function () use (&$order): void {
            $order[] = 'second';
        });

        $dispatcher->dispatch(new stdClass());

        expect($order)->toBe(['first', 'second']);
    });

    it('returns the event unchanged when nothing listens for it', function () {
        $dispatcher = new EventDispatcher();

        $event = new stdClass();
        $result = $dispatcher->dispatch($event);

        expect($result)->toBe($event);
    });

    it('does not call listeners registered for a different event class', function () {
        $dispatcher = new EventDispatcher();
        $called = false;

        $dispatcher->addListener(RuntimeException::class, function () use (&$called): void {
            $called = true;
        });

        $dispatcher->dispatch(new stdClass());

        expect($called)->toBeFalse();
    });

    it('stops calling further listeners once a StoppableEventInterface event reports propagation stopped', function () {
        $dispatcher = new EventDispatcher();
        $order = [];

        $dispatcher->addListener(StoppableTestEvent::class, function (StoppableTestEvent $event) use (&$order): void {
            $order[] = 'first';
            $event->stop();
        });
        $dispatcher->addListener(StoppableTestEvent::class, function () use (&$order): void {
            $order[] = 'second';
        });

        $dispatcher->dispatch(new StoppableTestEvent());

        expect($order)->toBe(['first']);
    });

    it('still calls every listener for a stoppable event that never stops propagation', function () {
        $dispatcher = new EventDispatcher();
        $order = [];

        $dispatcher->addListener(StoppableTestEvent::class, function () use (&$order): void {
            $order[] = 'first';
        });
        $dispatcher->addListener(StoppableTestEvent::class, function () use (&$order): void {
            $order[] = 'second';
        });

        $dispatcher->dispatch(new StoppableTestEvent());

        expect($order)->toBe(['first', 'second']);
    });
});
