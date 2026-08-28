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
 * The same enumeration carries the window's other half, the envelope those
 * tools answer with, so the two rules can never disagree about which tools
 * they apply to.
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

/**
 * The window's other half: what a caller is told about the cap on the way
 * back. list_categories took a limit and answered {"count": 1, "categories":
 * [...]}, so an agent handed exactly `limit` rows could not tell a page from
 * the whole set, nor even which cap produced it when the limit came from a
 * default it never sent.
 */
describe('the envelope every windowed tool answers with', function () use ($windowedTools, $methodSource) {
    // Built in Response so the shape cannot be repaired tool by tool and drift
    // apart again: paginated() for the tools that take an offset, capped() for
    // the ones that only cap.
    it('builds it in Response rather than by hand', function () use ($windowedTools, $methodSource) {
        $bare = array_values(array_map(
            static fn (array $tool): string => $tool['tool'],
            array_filter($windowedTools(), static function (array $tool) use ($methodSource): bool {
                $source = $methodSource($tool['class'], $tool['method']);

                return !str_contains($source, 'Response::paginated(')
                    && !str_contains($source, 'Response::capped(');
            }),
        ));
        sort($bare);

        // Named one by one rather than skipped, so a NEW list tool answering
        // with a bare count fails here instead of joining a silent majority,
        // and so fixing one of these fails until it comes off the list.
        //
        // What each still needs: get_queue_jobs already counts every status,
        // so the count for the status asked for is the total to hand capped().
        // read_logs and get_deprecations read a backward scan that stops as
        // soon as it has enough matches, so neither can produce a total
        // without redoing the whole read: they pass none and report the cap.
        expect($bare)->toBe(['get_deprecations', 'get_queue_jobs', 'read_logs']);
    });

    // The envelope mirrors the signature: a tool that takes an offset echoes
    // one and can be walked, and a tool that does not takes none back, because
    // a zero there would advertise paging that does not exist.
    it('pages exactly where the tool takes an offset', function () use ($windowedTools, $methodSource) {
        $mismatched = [];
        foreach ($windowedTools() as $tool) {
            $pages = str_contains($methodSource($tool['class'], $tool['method']), 'Response::paginated(');

            if ($pages !== in_array('offset', $tool['parameters'], true)) {
                $mismatched[] = $tool['tool'];
            }
        }

        expect($mismatched)->toBe([]);
    });
});
