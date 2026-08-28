<?php

declare(strict_types=1);

arch('source files have strict types')
    ->expect('stimmt\craft\Mcp')
    ->toUseStrictTypes();

arch('support classes are final')
    ->expect('stimmt\craft\Mcp\support')
    ->toBeFinal();

arch('tools do not use echo or print')
    ->expect('stimmt\craft\Mcp\tools')
    ->not->toUse(['echo', 'print', 'print_r', 'var_dump', 'dd']);

arch('no debugging functions in source')
    ->expect('stimmt\craft\Mcp')
    ->not->toUse(['dd', 'dump', 'var_dump']);

// print_r is banned as debug residue everywhere except the single class that
// renders OutputMode::PRINT_R, which is a tinker output mode a caller asks for
// by name. Transcript::printR() IS that feature; deleting the call would break
// the mode. The exemption is one class wide on purpose: Transcript stays bound
// by the rule above, and any other file reaching for print_r still fails.
arch('print_r only renders the tinker output mode')
    ->expect('stimmt\craft\Mcp')
    ->not->toUse('print_r')
    ->ignoring('stimmt\craft\Mcp\text\Transcript');

arch('events extend yii base Event')
    ->expect('stimmt\craft\Mcp\events')
    ->toExtend('yii\base\Event');

// src/models holds two different kinds of thing. Craft models (Settings) are
// configured, validated and saved by Craft, so they must extend its base Model.
// The three *Definition classes are not Craft models at all: they are final
// readonly DTOs mirroring the SDK's #[McpTool], #[McpPrompt] and #[McpResource]
// attributes field for field, and third-party plugins construct them
// positionally. Extending craft\base\Model would give them setters, scenarios
// and validation they must not have. They are named one by one rather than
// excluded as a namespace, so a new class in src/models has to make the choice
// deliberately instead of inheriting an exemption.
arch('craft models extend craft base Model')
    ->expect('stimmt\craft\Mcp\models')
    ->toExtend('craft\base\Model')
    ->ignoring([
        'stimmt\craft\Mcp\models\PromptDefinition',
        'stimmt\craft\Mcp\models\ResourceDefinition',
        'stimmt\craft\Mcp\models\ToolDefinition',
    ]);

arch('tool classes use strict types')
    ->expect('stimmt\craft\Mcp\tools')
    ->toUseStrictTypes();

// The error net is structural now: ErrorBoundary and Freshness decorate
// every call, so no tool, prompt or resource should carry its own guard. This
// keeps that true. Reintroducing one would not break anything visibly, it
// would just quietly restore 230 lines of ceremony that does nothing.
arch('elements do not guard themselves')
    ->expect(['stimmt\craft\Mcp\tools', 'stimmt\craft\Mcp\prompts', 'stimmt\craft\Mcp\resources'])
    ->not->toUse([
        'stimmt\craft\Mcp\support\SafeExecution',
        'stimmt\craft\Mcp\support\SafePromptExecution',
        'stimmt\craft\Mcp\support\SafeResourceExecution',
    ]);
