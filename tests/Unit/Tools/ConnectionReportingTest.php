<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\policy\Gate;
use stimmt\craft\Mcp\support\Build;
use stimmt\craft\Mcp\tools\McpTools;

/**
 * The informational tools have to answer "what may I call" with the same number
 * the server acts on. They did not: list_mcp_tools computed its enabled flag
 * from the settings alone, which is one of three reasons a tool can be
 * unavailable, so a readonly connection was told it had 55 tools when
 * tools/list offered 42 and marked the 13 it could not call enabled.
 *
 * Helpers are closures rather than named functions: Pest shares one global
 * function namespace, and tests/Unit/Tools/PrivilegedToolsTest.php already owns
 * `toolDefinition`.
 */
describe('the Gate as the connection', function () {
    it('carries the scope it was built with, so a tool can report it', function () {
        expect((new Gate(Scope::ReadOnly))->scope)->toBe(Scope::ReadOnly)
            ->and((new Gate(Scope::Full))->scope)->toBe(Scope::Full);
    });

    // Null is not "no permission", it is stdio: no token was presented, and
    // nothing is scoped away. Reporting it as a scope name would invent a
    // restriction that is not there.
    it('has no scope at all on an unscoped connection', function () {
        expect((new Gate())->scope)->toBeNull();
    });

    it('is what McpTools reads, rather than a second gate of its own', function () {
        $source = (string) file_get_contents((new ReflectionClass(McpTools::class))->getFileName());

        expect($source)->toContain('$this->gate->admitsTool(')
            // The settings-only check is exactly what produced the drift.
            ->and($source)->not->toContain("'enabled' => Mcp::isToolEnabled(");
    });
});

describe('build identity', function () {
    // The failure this guards: a path or symlink install runs code composer
    // never re-read, so its recorded version can name a branch that is no
    // longer checked out. Saying which source answered is what stops a claim
    // being mistaken for an observation.
    it('says whether the reference was observed or merely declared', function () {
        expect(Build::source())->toBeIn(['git', 'composer', 'unknown']);
    });

    it('reads the checked-out commit when the plugin is a working copy', function () {
        if (!is_dir(dirname(__DIR__, 3) . '/.git')) {
            expect(Build::source())->toBeIn(['composer', 'unknown']);

            return;
        }

        expect(Build::source())->toBe('git')
            ->and(Build::reference())->toMatch('/^[0-9a-f]{12}$/')
            ->and(Build::branch())->not->toBeEmpty();
    });

    it('shortens the reference to the same width whichever source answered', function () {
        $reference = Build::reference();

        expect($reference === null || strlen($reference) === 12)->toBeTrue();
    });
});
