<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\EventDispatcher;

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
});
