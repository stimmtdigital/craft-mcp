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
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r']);

arch('events extend yii base Event')
    ->expect('stimmt\craft\Mcp\events')
    ->toExtend('yii\base\Event');

arch('models extend craft base Model')
    ->expect('stimmt\craft\Mcp\models')
    ->toExtend('craft\base\Model');

arch('tool classes use strict types')
    ->expect('stimmt\craft\Mcp\tools')
    ->toUseStrictTypes();

// The error net is structural now: ErrorBoundary and ConfigRefresh decorate
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
