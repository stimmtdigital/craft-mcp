<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\integration\Status;

/**
 * Craft lets a guest control panel request whose first segment is a plugin
 * handle go no further than the login screen. This plugin's handle is `mcp`,
 * so an MCP endpoint at `{cpTrigger}/mcp…` serves MCP clients a login page.
 * The checks here are what the settings screen and `craft mcp/check` show.
 */
describe('Status::intercepted()', function () {
    it('decides whether a route lands on the plugin\'s control panel pages', function (string $route, ?string $cpTrigger, bool $intercepted) {
        expect(Status::intercepted($route, $cpTrigger))->toBe($intercepted);
    })->with([
        'a site route beside a cp trigger' => ['/mcp', 'admin', false],
        'mcp inside the control panel' => ['/admin/mcp', 'admin', true],
        // Outside the trigger nothing is the control panel's, wherever mcp is.
        'a site route with mcp further down' => ['/ai/mcp', 'cp', false],
        'the suggested cp route' => ['/admin/ai', 'admin', false],
        // Without a trigger the whole site is the control panel's namespace.
        'mcp with no cp trigger' => ['/mcp', null, true],
        'a sub-path with an empty trigger' => ['/mcp/auth', '', true],
        'the suggested route with no trigger' => ['/ai', null, false],
        // A segment that merely starts with the handle is a different segment.
        'a longer segment' => ['/mcpx', null, false],
    ]);
});

describe('Status::suggestedRoute()', function () {
    it('suggests /ai at the root, and under the cp trigger when there is one', function () {
        expect(Status::suggestedRoute(null))->toBe('/ai')
            ->and(Status::suggestedRoute('admin'))->toBe('/admin/ai');
    });
});

describe('Status::endpointCheck()', function () {
    it('passes routes that are reachable', function () {
        expect(Status::endpointCheck(['/admin/ai', '/admin/ai/auth'], 'admin')->level)->toBe(Level::Ok);
    });

    it('fails an intercepted route, naming it and the route to use instead', function () {
        $check = Status::endpointCheck(['/admin/mcp', '/admin/ai/auth'], 'admin');

        expect($check->level)->toBe(Level::Error)
            ->and($check->message)->toContain('/admin/mcp')
            ->and($check->message)->not->toContain('/admin/ai/auth')
            ->and($check->fix)->toContain('/admin/ai');
    });
});

// Craft 5.12 reads config/mcp.php into McpConfig and throws on a key it does
// not know, which takes its MCP server down. v1 kept the plugin's settings in
// that same file, so an upgraded install still carries them.
describe('Status::foreignKeys()', function () {
    beforeEach(function () {
        $this->previousApp = Craft::$app;
    });

    afterEach(function () {
        Craft::$app = $this->previousApp;
    });

    it('names the keys Craft\'s McpConfig does not have', function () {
        expect(Status::foreignKeys(['instructions' => 'x', 'enabled' => true]))->toBe(['enabled']);
    });

    it('finds nothing in a file holding only Craft\'s own keys', function () {
        expect(Status::foreignKeys(['instructions' => 'x', 'filters' => []]))->toBe([]);
    });

    // The multi-environment form: '*' merges with the current environment's
    // block. With no application the environment falls back to production,
    // which has no block here, so only the '*' keys are judged.
    it('resolves a multi-environment file before judging it', function () {
        Craft::$app = null;

        expect(Status::foreignKeys(['*' => ['httpTransport' => true], 'dev' => ['filters' => []]]))->toBe(['httpTransport']);
    });

    // In dev the dev block merges in; its Craft key is not foreign, and the
    // environment names themselves are never reported as keys.
    it('judges the current environment\'s block merged over the base', function () {
        Craft::$app = new class () {
            public string $env = 'dev';
        };

        expect(Status::foreignKeys(['*' => ['httpTransport' => true], 'dev' => ['filters' => [], 'oauth' => true]]))
            ->toBe(['httpTransport', 'oauth']);
    });
});
