<?php

declare(strict_types=1);

use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use stimmt\craft\Mcp\capabilities\AuditTools;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Gate;

/**
 * What a client learns about the two audit tools before calling them, and the
 * guards their bodies must hold. Helpers are closures because Pest shares one
 * global function namespace across the suite.
 */
$tool = static fn (string $method): McpTool => (new ReflectionMethod(AuditTools::class, $method))
    ->getAttributes(McpTool::class)[0]->newInstance();

$source = static function (string $method): string {
    $reflection = new ReflectionMethod(AuditTools::class, $method);
    $lines = explode("\n", (string) file_get_contents((string) $reflection->getFileName()));

    $start = $reflection->getStartLine() - 1;

    return implode("\n", array_slice($lines, $start, $reflection->getEndLine() - $start));
};

$schema = static fn (string $method): array => (new SchemaGenerator(new DocBlockParser()))
    ->generate(new ReflectionMethod(AuditTools::class, $method))['properties'];

describe('AuditTools', function () use ($tool, $source, $schema) {
    it('names the tools in Craft\'s {resource}_{verb} style under the stimmt_ prefix', function () use ($tool) {
        expect($tool('brokenLinks')->name)->toBe('stimmt_links_broken')
            ->and($tool('brokenLinks')->title)->toBe('Broken internal links')
            ->and($tool('unusedAssets')->name)->toBe('stimmt_assets_unused')
            ->and($tool('unusedAssets')->title)->toBe('Unused assets');
    });

    // Content reads in the Pro edition's site-management set: open to any
    // user with mcp:read, never behind the admin gate.
    it('declares both as idempotent Pro reads open to non-admins', function (string $method) use ($tool) {
        $reflection = new ReflectionMethod(AuditTools::class, $method);

        expect($tool($method)->annotations?->readOnlyHint)->toBeTrue()
            ->and($tool($method)->annotations?->idempotentHint)->toBeTrue()
            ->and($reflection->getAttributes(RequiresAdmin::class))->toBe([])
            ->and(Gate::requiredEdition($reflection))->toBe(Edition::Pro);
    })->with(['brokenLinks', 'unusedAssets']);

    it('publishes the bound on every call in the schema', function (string $method, int $max) use ($schema) {
        expect($schema($method)['limit'])->toMatchArray(['minimum' => 1, 'maximum' => $max])
            ->and($schema($method)['offset']['minimum'])->toBe(0);
    })->with([['brokenLinks', 1000], ['unusedAssets', 500]]);

    // Every read goes through the acting user's view scope and the response
    // budget; the window is checked before anything is queried.
    it('scopes, windows and budgets in its own body', function (string $method) use ($source) {
        $body = $source($method);

        expect($body)->toContain('Window::assert(')
            ->and($body)->toContain('Authorization::scopeQuery(')
            ->and($body)->toContain('Budget::assert(')
            ->and($body)->toContain('Response::paginated(');
    })->with(['brokenLinks', 'unusedAssets']);

    // "Unused" is a claim about Craft's own references only; the description
    // is the one place an agent reads that before acting on the list.
    it('warns that unused means unreferenced in Craft, not safe to delete', function () use ($tool) {
        $description = (string) $tool('unusedAssets')->description;

        expect($description)->toContain('not referenced in Craft')
            ->and($description)->toContain('hardcode')
            ->and($description)->toContain('never a list to delete');
    });

    it('says a target the user may not view shows no title', function () use ($tool) {
        expect((string) $tool('brokenLinks')->description)
            ->toContain('a target you may not view shows its id and reason, no title');
    });
});
