<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use Mcp\Schema\Tool;
use stimmt\craft\Mcp\discovery\Loader;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\policy\Gate;

/**
 * The edition is one clause in the Gate, next to the settings, scope and
 * privilege clauses, and it answers with the same Decision they do. Its third
 * outcome is the one the Decision comment was written for: the tool stays
 * listed but cannot do its work.
 *
 * Helpers are closures rather than named functions: Pest shares one global
 * function namespace and tests/Unit/Tools/PrivilegedToolsTest.php already owns
 * `toolDefinition`.
 */
$requiring = static fn (Edition $edition): ToolDefinition => new ToolDefinition(
    name: 'create_entry',
    description: 'fixture',
    class: 'Fixture',
    method: 'run',
    source: 'core',
    category: 'content',
    dangerous: true,
    privileged: false,
    requiredEdition: $edition,
);

$showLocked = static function (bool $visible): void {
    $settings = new Settings();
    $settings->showLockedProTools = $visible;
    (new ReflectionProperty(Mcp::class, 'loadedSettings'))->setValue(null, $settings);
};

// The plugin is not installed under test, so currentEdition() falls back to
// Lite. That is exactly the install this gate exists for.
afterEach(function (): void {
    (new ReflectionProperty(Mcp::class, 'loadedSettings'))->setValue(null, null);
});

describe('the edition clause', function () use ($requiring, $showLocked) {
    it('keeps a tool the running edition covers', function () use ($requiring, $showLocked) {
        $showLocked(false);

        expect((new Gate())->admitsTool($requiring(Edition::Lite))->allowed)->toBeTrue();
    });

    it('hides a tool above the running edition by default', function () use ($requiring, $showLocked) {
        $showLocked(false);
        $decision = (new Gate())->admitsTool($requiring(Edition::Pro));

        expect($decision->allowed)->toBeFalse()
            ->and($decision->substitutes())->toBeFalse()
            ->and($decision->reason)->toContain('pro');
    });

    it('keeps it visible but inert when the site owner asks for that', function () use ($requiring, $showLocked) {
        $showLocked(true);
        $decision = (new Gate())->admitsTool($requiring(Edition::Pro));

        expect($decision->substitutes())->toBeTrue()
            ->and($decision->label)->toBe('[Pro]')
            ->and($decision->notice)->toBe(Edition::proUpgradeMessage())
            // Still not allowed: it is listed, but it cannot do what it says.
            // Anything counting what this connection can really call is right
            // to leave it out.
            ->and($decision->allowed)->toBeFalse();
    });

    // The edition is checked last, so a tool refused for any earlier reason
    // reports that reason rather than advertising an upgrade the caller still
    // could not use.
    it('does not offer an upgrade for a tool that is disabled anyway', function () use ($showLocked) {
        $showLocked(true);

        $settings = new Settings();
        $settings->showLockedProTools = true;
        $settings->disabledTools = ['create_entry'];
        (new ReflectionProperty(Mcp::class, 'loadedSettings'))->setValue(null, $settings);

        $decision = (new Gate())->admitsTool(new ToolDefinition(
            name: 'create_entry',
            description: 'fixture',
            class: 'Fixture',
            method: 'run',
            source: 'core',
            category: 'content',
            dangerous: true,
            privileged: false,
            requiredEdition: Edition::Pro,
        ));

        expect($decision->allowed)->toBeFalse()
            ->and($decision->substitutes())->toBeFalse();
    });
});

it('registers a locked tool that can answer any call it is offered', function () {
    // A restrictive source schema, so relaxing it is observable.
    $tool = new Tool(
        name: 'create_entry',
        title: null,
        inputSchema: ['type' => 'object', 'required' => ['section'], 'properties' => ['section' => ['type' => 'string']]],
        description: 'Create an entry.',
        annotations: null,
    );

    $decision = stimmt\craft\Mcp\policy\Decision::substitute('requires pro', '[Pro]', Edition::proUpgradeMessage());
    // inert() reads neither the cache nor the gate; the constructor wants both.
    $cache = new class () implements Psr\SimpleCache\CacheInterface {
        public function get(string $key, mixed $default = null): mixed {
            return $default;
        }

        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool {
            return true;
        }

        public function delete(string $key): bool {
            return true;
        }

        public function clear(): bool {
            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable {
            return [];
        }

        public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool {
            return true;
        }

        public function has(string $key): bool {
            return false;
        }
    };

    $loader = new Loader(
        basePath: dirname(__DIR__, 3) . '/src',
        scanDirs: ['tools'],
        cache: $cache,
        gate: new Gate(),
        logger: new Psr\Log\NullLogger(),
    );
    $inert = (new ReflectionMethod(Loader::class, 'inert'))->invoke($loader, $tool, $decision);

    expect($inert->description)->toStartWith('[Pro]')
        ->and($inert->name)->toBe('create_entry')
        // Nothing mandatory, so a call carrying no arguments still reaches the
        // notice rather than being turned away by the validator first.
        ->and($inert->inputSchema['required'])->toBe([])
        // The discovered properties pass through untouched. Substituting a
        // hand-built empty schema encoded `properties` as [] rather than {},
        // and the SDK's own validator rejects that before the handler runs, so
        // every call to a locked tool answered with a schema error instead of
        // the sentence explaining why it cannot run.
        ->and($inert->inputSchema['properties'])->toBe(['section' => ['type' => 'string']])
        ->and(json_encode($inert->inputSchema))->toContain('"properties":{"section"');
});
