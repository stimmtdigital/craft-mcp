<?php

declare(strict_types=1);

use stimmt\craft\Mcp\utilities\Tokens;

// Craft and Twig are never booted in these tests: rendering a real CP
// screen requires a booted Craft::$app and view renderer. These assertions
// are structural instead: the four partials exist on disk and carry the
// load-bearing variable names and macro import the controller and utility
// class depend on, mirroring tests/Unit/Web/CpTest.php.
describe('CP token templates', function () {
    $base = dirname(__DIR__, 3) . '/src/templates/tokens';

    it('ships the four template partials', function () use ($base) {
        expect(file_exists("{$base}/_table.twig"))->toBeTrue()
            ->and(file_exists("{$base}/_create.twig"))->toBeTrue()
            ->and(file_exists("{$base}/_screen.twig"))->toBeTrue()
            ->and(file_exists("{$base}/_utility.twig"))->toBeTrue();
    });

    it('wires the revoke form in the table partial to revokeAction, with a showUser toggle', function () use ($base) {
        $source = (string) file_get_contents("{$base}/_table.twig");

        expect($source)->toContain('revokeAction')
            ->and($source)->toContain('showUser');
    });

    it('builds the scope select from allowedScopes using the forms macros', function () use ($base) {
        $source = (string) file_get_contents("{$base}/_create.twig");

        expect($source)->toContain('allowedScopes')
            ->and($source)->toContain('_includes/forms');
    });

    it('shows the show-once panel only when the newToken flash is present, and composes table + create', function () use ($base) {
        $source = (string) file_get_contents("{$base}/_screen.twig");

        expect($source)->toContain('newToken')
            ->and($source)->toContain('mcp/tokens/_table')
            ->and($source)->toContain('mcp/tokens/_create');
    });

    it('composes the table with showUser true and per-user manage links for the utility', function () use ($base) {
        $source = (string) file_get_contents("{$base}/_utility.twig");

        expect($source)->toContain('showUser: true')
            ->and($source)->toContain('mcp-tokens');
    });
});

describe('utilities\Tokens', function () {
    it('extends craft\base\Utility', function () {
        $class = new ReflectionClass(Tokens::class);

        expect($class->isSubclassOf(\craft\base\Utility::class))->toBeTrue();
    });

    it('exposes the mcp-tokens utility id', function () {
        expect(Tokens::id())->toBe('mcp-tokens');
    });

    it('renders the utility partial from contentHtml', function () {
        $source = (string) file_get_contents((new ReflectionClass(Tokens::class))->getFileName());

        expect($source)->toContain("renderTemplate('mcp/tokens/_utility'")
            ->and($source)->toContain('http\Tokens as TokenStore');
    });

    // The auto-generated utility:mcp-tokens permission is a separate grant
    // from managing tokens, so contentHtml must gate cross-user disclosure on
    // manageAllMcpTokens before it ever loads a token.
    it('guards the utility content on manageAllMcpTokens', function () {
        $source = (string) file_get_contents((new ReflectionClass(Tokens::class))->getFileName());
        $body = substr($source, (int) strpos($source, 'function contentHtml'));
        $body = substr($body, 0, (int) strpos($body, 'function view'));

        expect($body)->toContain("checkPermission('manageAllMcpTokens')")
            ->and($body)->toContain('return \'\';');
    });
});
