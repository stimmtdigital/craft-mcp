<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use craft\mcp\capabilities\Utilities;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use stimmt\craft\Mcp\capabilities\EntryWorkflowTools;
use stimmt\craft\Mcp\capabilities\GuideResources;
use stimmt\craft\Mcp\capabilities\NestedEntryTools;
use stimmt\craft\Mcp\capabilities\SystemTools;
use stimmt\craft\Mcp\capabilities\TinkerTools;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Gate;
use stimmt\craft\Mcp\models\Settings;

/**
 * The Gate is the only thing standing between a setting and what a client
 * sees: Craft builds its registry from the filtered discovery, so a tool the
 * Gate refuses is neither listed nor callable. Each case builds the same
 * ToolReference Craft hands the DISCOVERING_CAPABILITY event, from the SDK's
 * own classes, and asks the Gate directly.
 *
 * @param array{0: class-string, 1: string} $handler
 */
$tool = static fn (string $name, array $handler): ToolReference => new ToolReference(
    new Tool($name, null, ['type' => 'object', 'properties' => []], null, null),
    $handler,
);

/** @param array<string, mixed> $overrides */
$gate = static function (array $overrides = [], Edition $edition = Edition::Lite): Gate {
    $settings = new Settings();
    foreach ($overrides as $key => $value) {
        $settings->$key = $value;
    }

    return new Gate($settings, $edition);
};

describe('Gate on the plugin\'s own tools', function () use ($tool, $gate) {
    it('admits an ordinary tool with the default settings', function () use ($tool, $gate) {
        expect($gate()->admits($tool('stimmt_logs_read', [SystemTools::class, 'readLogs'])))->toBeTrue();
    });

    it('hides every tool when the plugin is disabled', function () use ($tool, $gate) {
        expect($gate(['enabled' => false])->admits($tool('stimmt_logs_read', [SystemTools::class, 'readLogs'])))->toBeFalse();
    });

    it('hides a tool listed in disabledTools, and only that one', function () use ($tool, $gate) {
        $gate = $gate(['disabledTools' => ['stimmt_logs_read']]);

        expect($gate->admits($tool('stimmt_logs_read', [SystemTools::class, 'readLogs'])))->toBeFalse()
            ->and($gate->admits($tool('stimmt_errors_last', [SystemTools::class, 'getLastError'])))->toBeTrue();
    });

    it('hides a #[Dangerous] tool while enableDangerousTools is off', function () use ($tool, $gate) {
        expect($gate(['enableDangerousTools' => false])->admits($tool('stimmt_tinker', [TinkerTools::class, 'tinker'])))->toBeFalse();
    });

    it('admits a #[Dangerous] tool while enableDangerousTools is on', function () use ($tool, $gate) {
        expect($gate(['enableDangerousTools' => true])->admits($tool('stimmt_tinker', [TinkerTools::class, 'tinker'])))->toBeTrue();
    });

    it('leaves a tool that is not dangerous alone when enableDangerousTools is off', function () use ($tool, $gate) {
        expect($gate(['enableDangerousTools' => false])->admits($tool('stimmt_logs_read', [SystemTools::class, 'readLogs'])))->toBeTrue();
    });

    it('hides a Pro tool on Lite', function (string $name, array $handler) use ($tool, $gate) {
        expect($gate([], Edition::Lite)->admits($tool($name, $handler)))->toBeFalse();
    })->with([
        ['stimmt_drafts_publish', [EntryWorkflowTools::class, 'publishEntry']],
        ['stimmt_nested_entries_create', [NestedEntryTools::class, 'createNestedEntry']],
    ]);

    it('admits a Pro tool on Pro', function (string $name, array $handler) use ($tool, $gate) {
        expect($gate([], Edition::Pro)->admits($tool($name, $handler)))->toBeTrue();
    })->with([
        ['stimmt_drafts_publish', [EntryWorkflowTools::class, 'publishEntry']],
        ['stimmt_nested_entries_create', [NestedEntryTools::class, 'createNestedEntry']],
    ]);

    // The handler Craft registers is a class-string pair, but an instance
    // pair must be judged by the same class.
    it('judges an instance handler by its class', function () use ($tool, $gate) {
        $instance = (new ReflectionClass(SystemTools::class))->newInstanceWithoutConstructor();

        expect($gate(['enabled' => false])->admits($tool('stimmt_logs_read', [$instance, 'readLogs'])))->toBeFalse();
    });

    it('hides the plugin\'s resources with the rest of the plugin', function () use ($gate) {
        $resource = new ElementReference([GuideResources::class, 'tools']);

        expect($gate(['enabled' => false])->admits($resource))->toBeFalse()
            ->and($gate()->admits($resource))->toBeTrue();
    });
});

// Policing Craft's own tools is not this plugin's business: a site that
// turns the plugin off must keep Craft's MCP server exactly as Craft ships it.
describe('Gate on Craft\'s own capabilities', function () use ($tool, $gate) {
    it('admits a Craft tool even with the plugin disabled, every gate shut and its name disabled', function () use ($tool, $gate) {
        $closed = $gate(['enabled' => false, 'enableDangerousTools' => false, 'disabledTools' => ['caches_list']], Edition::Lite);

        expect($closed->admits($tool('caches_list', [Utilities::class, 'listCaches'])))->toBeTrue();
    });

    it('admits a closure handler, which cannot be the plugin\'s', function () use ($tool, $gate) {
        $reference = new ToolReference(
            new Tool('anonymous', null, ['type' => 'object', 'properties' => []], null, null),
            static fn (): string => 'ok',
        );

        expect($gate(['enabled' => false])->admits($reference))->toBeTrue();
    });
});
