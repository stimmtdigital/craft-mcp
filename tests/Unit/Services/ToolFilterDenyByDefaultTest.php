<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\models\PromptDefinition;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\policy\Gate;

/**
 * Attribute discovery finds every #[McpTool] method there is, including ones on
 * classes the informational registry deliberately omits (a ConditionalProvider
 * reporting unavailable, for instance). Such an element has been offered to
 * none of the checks, so it must be refused rather than left live and
 * ungoverned (issue #57).
 *
 * This used to be a sweep that unregistered strays after the fact, tested by
 * reflecting into a private method on the server factory. The rule is now a
 * named decision the loader consults before registering anything, so the test
 * asks the rule instead of reaching through a class that happens to hold it.
 * The end-to-end result is covered by the smoke profiles, where a full,
 * content and readonly connection each advertise a different tool count.
 */
it('refuses an element the informational registry does not know', function () {
    $decision = (new Gate())->admitsUnknown();

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toContain('absent from the informational registry');
});

it('admits a tool the registry does know and settings allow', function () {
    $definition = new ToolDefinition(
        name: 'get_entry',
        description: 'fixture',
        class: 'Fixture',
        method: 'run',
        source: 'core',
        category: 'content',
        dangerous: false,
        privileged: false,
    );

    expect((new Gate())->admitsTool($definition)->allowed)->toBeTrue();
});

it('applies the same rule to prompts, which carry no scope axis', function () {
    $definition = new PromptDefinition(
        name: 'create_entry_guide',
        description: 'fixture',
        class: 'Fixture',
        method: 'run',
        source: 'core',
        category: 'content',
    );

    expect((new Gate())->admitsPrompt($definition)->allowed)->toBeTrue()
        ->and((new Gate())->admitsUnknown()->allowed)->toBeFalse();
});
