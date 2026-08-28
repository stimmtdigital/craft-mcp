<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\Window;

/**
 * The page window, swept across every tool that takes one.
 *
 * list_entries learned the rule and the eleven other tools taking the same two
 * parameters did not, so list_drafts answered `limit: -5` with all fifty-eight
 * rows while echoing "limit": -5 back. A guard that has to be remembered per
 * tool is forgotten at the next one, so this test does the remembering: it
 * finds every McpTool method with a limit or offset parameter by reflection
 * and holds each of them to both halves of the rule, the schema minimum a
 * client reads before it calls and the assert that holds without one.
 *
 * Helpers are closures because Pest shares one global function namespace
 * across the suite.
 *
 * @return list<array{class: class-string, method: string, tool: string, parameters: list<string>}>
 */
$windowedTools = static function (): array {
    $found = [];

    foreach ((array) glob(__DIR__ . '/../../../src/tools/*.php') as $file) {
        /** @var class-string $class */
        $class = 'stimmt\\craft\\Mcp\\tools\\' . basename((string) $file, '.php');

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $tool = $method->getAttributes(McpTool::class)[0] ?? null;
            if ($tool === null) {
                continue;
            }

            $parameters = array_values(array_filter(
                array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
                static fn (string $name): bool => in_array($name, ['limit', 'offset'], true),
            ));

            if ($parameters !== []) {
                $found[] = [
                    'class' => $class,
                    'method' => $method->getName(),
                    'tool' => (string) $tool->newInstance()->name,
                    'parameters' => $parameters,
                ];
            }
        }
    }

    return $found;
};

/** The source of one method, for the assertion that is about what it calls. */
$methodSource = static function (string $class, string $method): string {
    $reflection = new ReflectionMethod($class, $method);
    $lines = explode("\n", (string) file_get_contents((string) $reflection->getFileName()));

    return implode("\n", array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

describe('Window', function () {
    it('refuses a limit below one, naming the value it was given', function (int $limit) {
        expect(fn () => Window::assert($limit))
            ->toThrow(ToolCallException::class, "limit must be 1 or greater, got {$limit}.");
    })->with([[0], [-1], [-5]]);

    it('refuses a negative offset', function () {
        expect(fn () => Window::assert(20, -10))
            ->toThrow(ToolCallException::class, 'offset must be 0 or greater, got -10');
    });

    // Refused rather than clamped: the paginated responses echo both values
    // back, so a silent correction reads as a value that was honoured.
    it('leaves a valid window alone', function (int $limit, int $offset) {
        Window::assert($limit, $offset);

        expect(true)->toBeTrue();
    })->with([[20, 0], [1, 0], [500, 1000], [100, 0]]);

    it('defaults the offset, for the tools that only page by limit', function () {
        Window::assert(1);

        expect(true)->toBeTrue();
    });
});

describe('every tool taking a page window', function () use ($windowedTools, $methodSource) {
    it('finds the listing tools the rule has to hold for', function () use ($windowedTools) {
        $names = array_column($windowedTools(), 'tool');
        sort($names);

        // Spelled out rather than counted: a tool dropping its limit parameter
        // would keep any count honest while quietly leaving this sweep.
        expect($names)->toBe([
            'get_deprecations',
            'get_queue_jobs',
            'list_assets',
            'list_categories',
            'list_drafts',
            'list_entries',
            'list_orders',
            'list_products',
            'list_revisions',
            'list_users',
            'read_logs',
            'run_query',
        ]);
    });

    it('publishes the range on the schema, so a client can refuse it first', function () use ($windowedTools) {
        $generator = new SchemaGenerator(new DocBlockParser());
        $minimums = [
            'limit' => Window::MIN_LIMIT,
            'offset' => Window::MIN_OFFSET,
        ];

        $unbounded = [];
        foreach ($windowedTools() as $tool) {
            $properties = $generator->generate(new ReflectionMethod($tool['class'], $tool['method']))['properties'];

            foreach ($tool['parameters'] as $parameter) {
                if (($properties[$parameter]['minimum'] ?? null) !== $minimums[$parameter]) {
                    $unbounded[] = "{$tool['tool']}.{$parameter}";
                }
            }
        }

        expect($unbounded)->toBe([]);
    });

    // The schema minimum is what the SDK enforces on the wire; this is the
    // same range as the method's own invariant, which has to hold for a direct
    // PHP call and for any transport that does not validate.
    it('asserts the range in its own body as well', function () use ($windowedTools, $methodSource) {
        $unguarded = array_values(array_map(
            static fn (array $tool): string => $tool['tool'],
            array_filter(
                $windowedTools(),
                static fn (array $tool): bool => !str_contains(
                    $methodSource($tool['class'], $tool['method']),
                    'Window::assert(',
                ),
            ),
        ));

        expect($unguarded)->toBe([]);
    });

    // A negative limit dropped the LIMIT clause and returned the whole table,
    // so the guard has to run before the query is built, not after.
    it('asserts before it queries', function () use ($windowedTools, $methodSource) {
        $late = [];
        foreach ($windowedTools() as $tool) {
            // Window::MIN_LIMIT appears in the signature above the body, so
            // the call is matched by its own name rather than by the class.
            $source = $methodSource($tool['class'], $tool['method']);
            $guard = strpos($source, 'Window::assert(');
            $query = strpos($source, '::find()');

            if ($guard === false || ($query !== false && $guard > $query)) {
                $late[] = $tool['tool'];
            }
        }

        expect($late)->toBe([]);
    });
});
