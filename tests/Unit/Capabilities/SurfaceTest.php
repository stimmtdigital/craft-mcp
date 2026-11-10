<?php

declare(strict_types=1);

use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\capabilities\BackupTools;
use stimmt\craft\Mcp\capabilities\DatabaseTools;
use stimmt\craft\Mcp\capabilities\DebugTools;
use stimmt\craft\Mcp\capabilities\GraphqlTools;
use stimmt\craft\Mcp\capabilities\SystemTools;
use stimmt\craft\Mcp\capabilities\TinkerTools;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Gate;

/**
 * The tool surface the plugin adds to Craft's MCP server, read off the
 * attributes Craft itself discovers. Every list here is closed and spelled
 * out: a count stays honest while the wrong tool quietly takes a slot.
 *
 * Helpers are closures because Pest shares one global function namespace
 * across the suite.
 */

/**
 * Every #[McpTool] method in a directory of capability classes.
 *
 * @return list<array{class: class-string, method: ReflectionMethod, tool: McpTool}>
 */
$toolsIn = static function (string $directory, string $namespace): array {
    $tools = [];

    foreach ((array) glob($directory . '/*.php') as $file) {
        /** @var class-string $class */
        $class = $namespace . basename((string) $file, '.php');

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $tools[] = ['class' => $class, 'method' => $method, 'tool' => $attribute->newInstance()];
            }
        }
    }

    return $tools;
};

$ownTools = static fn (): array => $toolsIn(dirname(__DIR__, 3) . '/src/capabilities', 'stimmt\\craft\\Mcp\\capabilities\\');

/** @param list<array{tool: McpTool}> $tools */
$names = static fn (array $tools): array => array_map(static fn (array $tool): string => (string) $tool['tool']->name, $tools);

/** The names of the plugin's tools that satisfy $keep, sorted. */
$namesWhere = static function (callable $keep) use ($ownTools, $names): array {
    $selected = $names(array_values(array_filter($ownTools(), $keep)));
    sort($selected);

    return $selected;
};

$isPro = static fn (array $tool): bool => Gate::requiredEdition($tool['method']) === Edition::Pro;
$isDangerous = static fn (array $tool): bool => $tool['method']->getAttributes(Dangerous::class) !== [];

/**
 * The tools that change something: the Pro content writes, plus the three
 * dangerous tools that run code or write files. stimmt_db_query is dangerous
 * (raw SQL against the install) but refuses anything that is not a read, so it
 * is not here.
 */
$writeTools = [
    'stimmt_backups_create',
    'stimmt_drafts_publish',
    'stimmt_entries_copy_to_site',
    'stimmt_graphql_execute',
    'stimmt_nested_entries_create',
    'stimmt_nested_entries_move',
    'stimmt_tinker',
];

describe('tool names', function () use ($ownTools, $names, $toolsIn) {
    it('finds the plugin\'s tools at all', function () use ($ownTools) {
        expect(count($ownTools()))->toBeGreaterThan(30);
    });

    // The prefix is what keeps the plugin's tools apart from Craft's own in a
    // client's list and in Craft's registry.
    it('prefixes every tool with stimmt_', function () use ($ownTools, $names) {
        $unprefixed = array_values(array_filter($names($ownTools()), static fn (string $name): bool => !str_starts_with($name, 'stimmt_')));

        expect($unprefixed)->toBe([]);
    });

    it('never declares one name twice', function () use ($ownTools, $names) {
        $all = $names($ownTools());

        expect(array_values(array_unique(array_diff_assoc($all, array_unique($all)))))->toBe([]);
    });

    // Craft's registry throws a LogicException on a duplicate tool name, and
    // it builds one registry for the whole server, so a single collision takes
    // every tool down, Craft's included. The prefix makes that unlikely; this
    // makes it impossible to ship unnoticed after a Craft update adds a tool.
    it('collides with no tool Craft\'s own MCP server declares', function () use ($ownTools, $names, $toolsIn) {
        $craft = $names($toolsIn(dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/mcp/capabilities', 'craft\\mcp\\capabilities\\'));

        expect(count($craft))->toBeGreaterThan(50)
            ->and(array_values(array_intersect($names($ownTools()), $craft)))->toBe([]);
    });
});

describe('resource uris', function () {
    it('puts every resource under the stimmt:// scheme', function () {
        $uris = [];
        foreach ((array) glob(dirname(__DIR__, 3) . '/src/capabilities/*.php') as $file) {
            $class = 'stimmt\\craft\\Mcp\\capabilities\\' . basename((string) $file, '.php');
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(McpResource::class) as $attribute) {
                    $uris[] = $attribute->newInstance()->uri;
                }
            }
        }

        expect($uris)->not->toBeEmpty()
            ->and(array_values(array_filter($uris, static fn (string $uri): bool => !str_starts_with($uri, 'stimmt://'))))->toBe([]);
    });
});

describe('the admin gate', function () {
    // Craft derives the mcp:admin scope from #[RequiresAdmin] and keeps these
    // tools from every non-admin. They read the database, the config, the
    // logs and the install's code paths, so a method without it would be
    // offered to any editor with mcp:read.
    it('marks every tool in the developer classes admin-only', function (string $class) {
        $open = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(McpTool::class) !== [] && $method->getAttributes(RequiresAdmin::class) === []) {
                $open[] = $method->getName();
            }
        }

        expect($open)->toBe([]);
    })->with([
        [DatabaseTools::class],
        [DebugTools::class],
        [SystemTools::class],
        [TinkerTools::class],
        [GraphqlTools::class],
        [BackupTools::class],
    ]);
});

describe('closed sets', function () use ($namesWhere, $isPro, $isDangerous) {
    // Production forces enableDangerousTools off, so this list is what an
    // admin loses there. A stray #[Dangerous] silently removes a tool from
    // production; a missing one ships code execution to it.
    it('marks exactly the four code, SQL, GraphQL-write and backup tools dangerous', function () use ($namesWhere, $isDangerous) {
        expect($namesWhere($isDangerous))->toBe([
            'stimmt_backups_create',
            'stimmt_db_query',
            'stimmt_graphql_execute',
            'stimmt_tinker',
        ]);
    });

    it('sells exactly the four content writes as Pro', function () use ($namesWhere, $isPro) {
        expect($namesWhere($isPro))->toBe([
            'stimmt_drafts_publish',
            'stimmt_entries_copy_to_site',
            'stimmt_nested_entries_create',
            'stimmt_nested_entries_move',
        ]);
    });
});

// Craft maps readOnlyHint to the OAuth scope: true needs mcp:read, anything
// else mcp:write. A read without the hint demands a write grant it does not
// need; a write WITH it would be handed to a read-only connection.
describe('read and write annotations', function () use ($namesWhere, $writeTools) {
    it('marks every tool that is not a write read-only', function () use ($namesWhere, $writeTools) {
        $unmarked = $namesWhere(static fn (array $tool): bool => !in_array($tool['tool']->name, $writeTools, true)
            && $tool['tool']->annotations?->readOnlyHint !== true);

        expect($unmarked)->toBe([]);
    });

    it('never marks a write read-only', function () use ($namesWhere, $writeTools) {
        $writes = $namesWhere(static fn (array $tool): bool => in_array($tool['tool']->name, $writeTools, true));
        $readOnly = $namesWhere(static fn (array $tool): bool => in_array($tool['tool']->name, $writeTools, true)
            && $tool['tool']->annotations?->readOnlyHint === true);

        expect($writes)->toBe($writeTools)
            ->and($readOnly)->toBe([]);
    });
});
