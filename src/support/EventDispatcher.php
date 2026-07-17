<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Minimal PSR-14 dispatcher: listeners register against an exact event
 * class and run in registration order, honoring StoppableEventInterface. No
 * inheritance matching; the SDK's own events are final, so exact-class
 * lookup is all the MCP server's list-changed capabilities need.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EventDispatcher implements EventDispatcherInterface {
    /**
     * @var array<class-string, list<callable(object): void>>
     */
    private array $listeners = [];

    /**
     * @param callable(object): void $listener
     */
    public function addListener(string $eventClass, callable $listener): void {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): object {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}
