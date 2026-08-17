<?php

declare(strict_types=1);

use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\policy\Decision;
use stimmt\craft\Mcp\policy\Gate;
use stimmt\craft\Mcp\tools\BackupTools;
use stimmt\craft\Mcp\tools\CommerceTools;
use stimmt\craft\Mcp\tools\DatabaseTools;
use stimmt\craft\Mcp\tools\DebugTools;
use stimmt\craft\Mcp\tools\GlobalSetTools;
use stimmt\craft\Mcp\tools\GraphqlTools;
use stimmt\craft\Mcp\tools\SystemTools;

function toolDefinition(bool $privileged): ToolDefinition {
    return new ToolDefinition(
        name: 'a_tool',
        description: 'fixture',
        class: 'Fixture',
        method: 'run',
        source: 'test',
        category: 'system',
        dangerous: false,
        privileged: $privileged,
    );
}

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
    // get_last_error returns a log line (bypasses the read_logs lock otherwise);
    // list_globals returns stored global content; list_backups exposes absolute
    // filesystem paths. All locked for scoped tokens.
    [SystemTools::class, 'getLastError'],
    [GlobalSetTools::class, 'listGlobals'],
    [BackupTools::class, 'listBackups'],
]);

// The decision itself, rather than the source text of whichever class happens
// to hold it. The previous version asserted that McpServerFactory's file
// mentioned 'privileged', which pinned a location instead of a behaviour and
// broke the moment the rule moved to its own class without changing.
it('admits a plain tool on any scope', function (?Scope $scope) {
    $decision = (new Gate($scope))->admitsTool(toolDefinition(privileged: false));

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reason)->toBeNull();
})->with([null, Scope::Full]);

it('admits a privileged tool on stdio and full scope, where the axis does not apply', function (?Scope $scope) {
    expect((new Gate($scope))->admitsTool(toolDefinition(privileged: true))->allowed)->toBeTrue();
})->with([null, Scope::Full]);

it('explains a denial rather than just refusing', function () {
    $decision = Decision::deny('outside the readonly scope');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe('outside the readonly scope');
});
