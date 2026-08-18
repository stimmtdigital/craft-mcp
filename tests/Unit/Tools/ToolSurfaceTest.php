<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use stimmt\craft\Mcp\tools\AssetTools;
use stimmt\craft\Mcp\tools\DatabaseTools;
use stimmt\craft\Mcp\tools\DebugTools;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;
use stimmt\craft\Mcp\tools\GraphqlTools;
use stimmt\craft\Mcp\tools\NestedEntryTools;
use stimmt\craft\Mcp\tools\SystemTools;

/**
 * What a client can learn about a tool WITHOUT calling anything: the display
 * name in a tool picker, and the description on each schema property.
 *
 * Helpers are closures rather than named functions, because Pest shares one
 * global function namespace across the whole suite and
 * tests/Unit/Tools/OutputConventionTest.php already owns `schemaFor`.
 */

/** @return McpTool[] */
$declaredTools = static function (): array {
    $tools = [];

    foreach ((array) glob(__DIR__ . '/../../../src/tools/*.php') as $file) {
        $class = 'stimmt\\craft\\Mcp\\tools\\' . basename((string) $file, '.php');

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $tools[] = $attribute->newInstance();
            }
        }
    }

    return $tools;
};

$generatedSchema = static fn (string $class, string $method): array => (new SchemaGenerator(new DocBlockParser()))
    ->generate(new ReflectionMethod($class, $method));

describe('tool titles', function () use ($declaredTools) {
    // Tool::$title is the spec's display name since revision 2025-06-18 and
    // serializes only when set. Without it a client rendering a tool picker
    // shows the raw snake_case name. No exemption list: an exemption list is
    // how this gap reopens one tool at a time.
    it('gives every tool a display name', function () use ($declaredTools) {
        $untitled = array_values(array_map(
            static fn (McpTool $tool): ?string => $tool->name,
            array_filter(
                $declaredTools(),
                static fn (McpTool $tool): bool => $tool->title === null || trim($tool->title) === '',
            ),
        ));

        expect($untitled)->toBe([]);
    });

    it('gives each tool its own, so a picker can tell two rows apart', function () use ($declaredTools) {
        $titles = array_map(static fn (McpTool $tool): ?string => $tool->title, $declaredTools());

        expect(array_diff_assoc($titles, array_unique($titles)))->toBe([]);
    });

    // A title is a name, not a second description: a client shows it in a list
    // where the description already has its own place.
    it('keeps every title short enough to render as a label', function () use ($declaredTools) {
        $overlong = array_values(array_map(
            static fn (McpTool $tool): ?string => $tool->name,
            array_filter($declaredTools(), static fn (McpTool $tool): bool => mb_strlen((string) $tool->title) > 60),
        ));

        expect($overlong)->toBe([]);
    });
});

describe('parameter descriptions', function () use ($generatedSchema) {
    // Generated with the SDK's own generator from the real signatures, so a
    // description dropped from a #[Schema] attribute fails here rather than
    // silently leaving the agent to guess in production. These are the
    // parameters whose meaning cannot be read off the name: a payload format,
    // a value grammar, a default that surprises, or a matching rule.
    it('describes the parameters an agent cannot infer', function (string $class, string $method, array $parameters) use ($generatedSchema) {
        $properties = $generatedSchema($class, $method)['properties'];

        $undescribed = array_values(array_filter(
            $parameters,
            static fn (string $name): bool => trim((string) ($properties[$name]['description'] ?? '')) === '',
        ));

        expect($undescribed)->toBe([]);
    })->with([
        'list_entries' => [EntryTools::class, 'listEntries', ['status', 'type', 'search', 'author', 'updatedAfter', 'updatedBefore', 'createdAfter', 'createdBefore']],
        'count_entries' => [EntryTools::class, 'countEntries', ['status', 'groupBy']],
        'get_entry' => [EntryTools::class, 'getEntry', ['id', 'slug']],
        'create_entry' => [EntryTools::class, 'createEntry', ['fields', 'mode', 'parent', 'postDate', 'expiryDate', 'slug']],
        'update_entry' => [EntryTools::class, 'updateEntry', ['id', 'fields', 'mode', 'status', 'expectedDateUpdated']],
        'describe_entry_schema' => [EntryTools::class, 'describeEntrySchema', ['type', 'depth', 'example']],
        'publish_entry' => [EntryWorkflowTools::class, 'publishEntry', ['id']],
        'delete_entry' => [EntryWorkflowTools::class, 'deleteEntry', ['id']],
        'duplicate_entry' => [EntryWorkflowTools::class, 'duplicateEntry', ['fields']],
        'copy_entry_to_site' => [EntryWorkflowTools::class, 'copyEntryToSite', ['fromSite', 'toSite']],
        'list_drafts' => [EntryWorkflowTools::class, 'listDrafts', ['creator']],
        'create_nested_entry' => [NestedEntryTools::class, 'createNestedEntry', ['owner', 'field', 'type', 'fields', 'mode', 'position']],
        'move_nested_entry' => [NestedEntryTools::class, 'moveNestedEntry', ['id', 'position', 'mode']],
        'list_assets' => [AssetTools::class, 'listAssets', ['volume', 'folderId', 'kind', 'filename']],
        'run_query' => [DatabaseTools::class, 'runQuery', ['sql', 'limit']],
        'get_database_schema' => [DatabaseTools::class, 'getDatabaseSchema', ['table']],
        'get_queue_jobs' => [DebugTools::class, 'getQueueJobs', ['status']],
        'list_event_handlers' => [DebugTools::class, 'listEventHandlers', ['filter']],
        'query_graphql' => [GraphqlTools::class, 'queryGraphql', ['query', 'variables', 'operationName', 'schemaId']],
        'execute_graphql' => [GraphqlTools::class, 'executeGraphql', ['query']],
        'get_config' => [SystemTools::class, 'getConfig', ['key']],
        'read_logs' => [SystemTools::class, 'readLogs', ['level', 'pattern', 'source']],
        'clear_caches' => [SystemTools::class, 'clearCaches', ['type']],
    ]);

    // A description-only #[Schema] must not disturb what the generator inferred
    // from the signature: the merge puts parameter-level keys last, so writing
    // one is safe on a typed or enum parameter. If that ever stopped holding,
    // every description added this way would silently reshape a schema.
    it('adds the description without reshaping the parameter', function () use ($generatedSchema) {
        $properties = $generatedSchema(EntryTools::class, 'createEntry')['properties'];

        expect($properties['fields']['type'])->toBe(['null', 'string'])
            ->and($properties['section']['type'])->toBe('string')
            ->and($generatedSchema(EntryTools::class, 'describeEntrySchema')['properties']['depth'])
            ->toMatchArray(['type' => 'integer', 'default' => 1]);
    });
});
