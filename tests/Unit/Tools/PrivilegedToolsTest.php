<?php

declare(strict_types=1);

use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\services\McpServerFactory;
use stimmt\craft\Mcp\tools\CommerceTools;
use stimmt\craft\Mcp\tools\DatabaseTools;
use stimmt\craft\Mcp\tools\DebugTools;
use stimmt\craft\Mcp\tools\GraphqlTools;
use stimmt\craft\Mcp\tools\SystemTools;

// Install-introspection reads (logs, config, db schema/contents,
// environment) are locked to admins by default over read-scoped HTTP
// tokens; the site owner opens specific tools via config. Full scope and
// stdio (no scope) never gate on this axis.
it('adds a privileged flag defaulting to false', function () {
    $meta = new McpToolMeta(category: ToolCategory::SYSTEM);
    expect($meta->privileged)->toBeFalse();
});

it('flags the install-introspection reads privileged', function (string $class, string $method) {
    $meta = (new ReflectionMethod($class, $method))->getAttributes(McpToolMeta::class)[0]->newInstance();
    expect($meta->privileged)->toBeTrue();
})->with([
    [SystemTools::class, 'readLogs'],
    [SystemTools::class, 'getConfig'],
    [DatabaseTools::class, 'getDatabaseSchema'],
    [DatabaseTools::class, 'getDatabaseInfo'],
    [DatabaseTools::class, 'getTableCounts'],
    [DebugTools::class, 'getProjectConfigDiff'],
    [DebugTools::class, 'getEnvironment'],
    // GraphQL reads authorize via the schema's own scope, not the acting
    // user's Craft view permissions, so they are unbounded relative to a
    // scoped token and locked the same way. list_graphql_tokens also inventories
    // the install's API-credential surface.
    [GraphqlTools::class, 'queryGraphql'],
    [GraphqlTools::class, 'listGraphqlTokens'],
    // Commerce order/product reads expose customer PII and catalog data with
    // no per-user Commerce permission scoping yet; locked as a stopgap until
    // proper Commerce view-permission scoping lands.
    [CommerceTools::class, 'listOrders'],
    [CommerceTools::class, 'getOrder'],
    [CommerceTools::class, 'listProducts'],
    [CommerceTools::class, 'getProduct'],
]);

it('filters privileged tools for enforced scopes in the factory', function () {
    $src = (string) file_get_contents((new ReflectionClass(McpServerFactory::class))->getFileName());

    expect($src)->toContain('privileged')
        ->and($src)->toContain('scopedTokenPrivilegedTools');
});
