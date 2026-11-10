<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use stimmt\craft\Mcp\capabilities\DatabaseTools;
use stimmt\craft\Mcp\capabilities\DebugTools;
use stimmt\craft\Mcp\capabilities\EntryTools;
use stimmt\craft\Mcp\capabilities\EntryWorkflowTools;
use stimmt\craft\Mcp\capabilities\GraphqlTools;
use stimmt\craft\Mcp\capabilities\NestedEntryTools;
use stimmt\craft\Mcp\capabilities\SystemTools;

/**
 * What a client can learn about a tool WITHOUT calling anything: the display
 * name in a tool picker, and the description on each schema property.
 *
 * Helpers are closures rather than named functions, because Pest shares one
 * global function namespace across the whole suite.
 */

/** @return McpTool[] */
$declaredTools = static function (): array {
    $tools = [];

    foreach ((array) glob(__DIR__ . '/../../../src/capabilities/*.php') as $file) {
        $class = 'stimmt\\craft\\Mcp\\capabilities\\' . basename((string) $file, '.php');

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
        'stimmt_entries_count' => [EntryTools::class, 'countEntries', ['section', 'status', 'groupBy', 'filters', 'relatedTo']],
        'stimmt_refs_resolve' => [EntryTools::class, 'resolveRef', ['type', 'key']],
        'stimmt_entries_preview_url' => [EntryTools::class, 'previewUrl', ['id']],
        'stimmt_drafts_publish' => [EntryWorkflowTools::class, 'publishEntry', ['id', 'site']],
        'stimmt_entries_copy_to_site' => [EntryWorkflowTools::class, 'copyEntryToSite', ['fromSite', 'toSite']],
        'stimmt_nested_entries_create' => [NestedEntryTools::class, 'createNestedEntry', ['owner', 'field', 'type', 'fields', 'mode', 'position']],
        'stimmt_nested_entries_move' => [NestedEntryTools::class, 'moveNestedEntry', ['id', 'position', 'mode']],
        'stimmt_db_query' => [DatabaseTools::class, 'runQuery', ['sql', 'limit']],
        'stimmt_db_schema' => [DatabaseTools::class, 'getDatabaseSchema', ['table']],
        'stimmt_event_handlers_list' => [DebugTools::class, 'listEventHandlers', ['filter']],
        'stimmt_graphql_query' => [GraphqlTools::class, 'queryGraphql', ['query', 'variables', 'operationName', 'schemaId']],
        'stimmt_graphql_execute' => [GraphqlTools::class, 'executeGraphql', ['query']],
        'stimmt_config_get' => [SystemTools::class, 'getConfig', ['key']],
        'stimmt_logs_read' => [SystemTools::class, 'readLogs', ['level', 'pattern', 'source']],
    ]);

    // A description-only #[Schema] must not disturb what the generator inferred
    // from the signature: the merge puts parameter-level keys last, so writing
    // one is safe on a typed or enum parameter. If that ever stopped holding,
    // every description added this way would silently reshape a schema.
    it('adds the description without reshaping the parameter', function () use ($generatedSchema) {
        $properties = $generatedSchema(NestedEntryTools::class, 'createNestedEntry')['properties'];

        expect($properties['fields']['type'])->toBe(['null', 'string'])
            ->and($properties['field']['type'])->toBe('string')
            ->and($generatedSchema(SystemTools::class, 'readLogs')['properties']['limit'])
            ->toMatchArray(['type' => 'integer', 'default' => 50]);
    });
});
