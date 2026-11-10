<?php

declare(strict_types=1);

arch('source files have strict types')
    ->expect('stimmt\craft\Mcp')
    ->toUseStrictTypes();

arch('support classes are final')
    ->expect('stimmt\craft\Mcp\support')
    ->toBeFinal();

arch('capabilities do not use echo or print')
    ->expect('stimmt\craft\Mcp\capabilities')
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

// Craft models (Settings) are configured, validated and saved by Craft, so
// they must extend its base Model. v1 kept three readonly *Definition DTOs
// here with an exemption each; they went with the plugin's own server, so
// there is no exemption left and a new class in src/models has to be a model.
arch('craft models extend craft base Model')
    ->expect('stimmt\craft\Mcp\models')
    ->toExtend('craft\base\Model');

arch('capability classes use strict types')
    ->expect('stimmt\craft\Mcp\capabilities')
    ->toUseStrictTypes();

// v1 ran every call through its own pipeline (ErrorBoundary, Freshness), so
// a per-tool guard was ceremony. v2 runs inside Craft's MCP server, which
// turns a thrown ToolCallException into a tool error itself. Either way no
// capability should carry a wrapper of its own; this keeps the v1 wrappers
// from coming back under the new namespace.
arch('capabilities do not guard themselves')
    ->expect('stimmt\craft\Mcp\capabilities')
    ->not->toUse([
        'stimmt\craft\Mcp\support\SafeExecution',
        'stimmt\craft\Mcp\support\SafePromptExecution',
        'stimmt\craft\Mcp\support\SafeResourceExecution',
    ]);

// v2 is not a server. The transport, the session store, the OAuth flow, the
// call pipeline and the registries all belong to Craft's MCP server since
// 5.12, and a second copy of any of them is a second server answering on the
// same routes. Nothing under src may reach back into those v1 namespaces.
// Pest only sees a dependency on a class that exists, so this bites the moment
// a class is put back under one of them and used, which is the regression.
arch('nothing reintroduces the v1 server')
    ->expect('stimmt\craft\Mcp')
    ->not->toUse([
        'stimmt\craft\Mcp\http',
        'stimmt\craft\Mcp\transport',
        'stimmt\craft\Mcp\pipeline',
        'stimmt\craft\Mcp\services',
    ]);
