<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Closure;
use Craft;
use Generator;
use ReflectionClass;
use ReflectionFunction;
use Throwable;
use yii\base\Event;

/**
 * Reads the event handlers registered across the running application.
 *
 * WHY it is not on the tool: this is the whole implementation of one tool out
 * of six on DebugTools, and it was a quarter of that class. A reader looking
 * for what the debugging tools expose had to scroll past the reflection
 * machinery of a single one of them, and the class was over the size limit
 * because of it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EventHandlers {
    /**
     * Get application-level event handlers.
     */
    public function applicationEvents(?string $filter): array {
        try {
            $reflection = new ReflectionClass(Craft::$app);
            $eventsProperty = $reflection->getProperty('_events');
            $events = $eventsProperty->getValue(Craft::$app) ?? [];
        } catch (Throwable) {
            return [];
        }

        $handlers = [];
        foreach ($events as $eventName => $eventHandlers) {
            if ($filter !== null && stripos((string) $eventName, $filter) === false) {
                continue;
            }

            $handlers[$eventName] = [
                'count' => count($eventHandlers),
                'handlers' => $this->describeHandlers($eventHandlers),
            ];
        }

        return $handlers;
    }

    /**
     * Get class-level event handlers (Events::on()).
     */
    public function classEvents(?string $filter): array {
        try {
            $eventReflection = new ReflectionClass(Event::class);
            $classEventsProperty = $eventReflection->getStaticPropertyValue('_events');
        } catch (Throwable) {
            return [];
        }

        $classEvents = [];
        foreach ($this->flattenClassEvents($classEventsProperty, $filter) as $event) {
            $key = "{$event['class']}::{$event['event']}";
            $classEvents[$key] = $event;
        }

        return $classEvents;
    }

    /**
     * Flatten nested class events structure.
     *
     * Yii stores class-level handlers as `Event::$_events[$eventName][$className][]`,
     * so the outer key is the event name and the inner key is the class name.
     *
     * @return Generator<array{class: string, event: string, count: int, handlers: array}>
     */
    private function flattenClassEvents(array $classEventsProperty, ?string $filter): Generator {
        $flattened = array_merge(...array_map(
            fn (string $eventName, array $classHandlers) => $this->extractClassEvents($eventName, $classHandlers, $filter),
            array_keys($classEventsProperty),
            array_values($classEventsProperty),
        ));

        yield from $flattened;
    }

    /**
     * Extract the per-class handlers registered for a single event name.
     *
     * @param array<string, array> $classHandlers keyed by class name
     * @return array<array{class: string, event: string, count: int, handlers: array}>
     */
    private function extractClassEvents(string $eventName, array $classHandlers, ?string $filter): array {
        return array_filter(
            array_map(
                fn (string $className, array $eventHandlerList) => $this->matchesFilter($className, $eventName, $filter)
                    ? [
                        'class' => $className,
                        'event' => $eventName,
                        'count' => count($eventHandlerList),
                        'handlers' => $this->describeHandlers($eventHandlerList),
                    ]
                    : null,
                array_keys($classHandlers),
                array_values($classHandlers),
            ),
        );
    }

    /**
     * Check if class or event name matches filter.
     */
    private function matchesFilter(string $className, string $eventName, ?string $filter): bool {
        if ($filter === null) {
            return true;
        }

        return stripos($eventName, $filter) !== false || stripos($className, $filter) !== false;
    }

    /**
     * Describe a list of event handlers.
     */
    private function describeHandlers(array $handlers): array {
        return array_map(
            fn (array $handler) => $this->describeCallback($handler[0]),
            $handlers,
        );
    }

    /**
     * Describe a callback for human readability.
     */
    private function describeCallback(mixed $callback): string {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            $class = is_object($callback[0]) ? $callback[0]::class : $callback[0];
            $method = $callback[1];

            return "{$class}::{$method}()";
        }

        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);
            $file = basename($reflection->getFileName());
            $line = $reflection->getStartLine();

            return "Closure in {$file}:{$line}";
        }

        if (is_object($callback)) {
            return $callback::class . '::__invoke()';
        }

        return 'unknown';
    }
}
