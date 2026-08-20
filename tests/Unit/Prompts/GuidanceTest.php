<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpPrompt;

// A prompt tells an agent how to drive the tools, so a claim it makes that the
// tools no longer honour is worse than no prompt at all: the agent follows it.
// These are the capabilities the write and read tools gained, pinned to the
// prompt that has to mention them, so a later tool change that drops one shows
// up here as a stale prompt instead of as an agent quietly doing the old thing.
beforeEach(function () {
    $this->promptBodies = static function (): array {
        $bodies = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/prompts/*.php') as $file) {
            $class = 'stimmt\\craft\\Mcp\\prompts\\' . basename($file, '.php');

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $attributes = $method->getAttributes(McpPrompt::class);
                if ($attributes === []) {
                    continue;
                }

                $name = $attributes[0]->newInstance()->name ?? $method->getName();
                $lines = file((string) $method->getFileName());
                $bodies[$name] = implode('', array_slice(
                    $lines === false ? [] : $lines,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1,
                ));
            }
        }

        return $bodies;
    };
});

it('states the current write contract in the prompts that guide writes', function (string $prompt, array $claims) {
    $body = ($this->promptBodies)()[$prompt] ?? null;

    expect($body)->not->toBeNull();

    foreach ($claims as $claim) {
        expect($body)->toContain($claim);
    }
})->with([
    // create_entry accepts postDate/expiryDate as named arguments, natural keys
    // resolve against unpublished drafts, Matrix blocks carry a position, and
    // single-block work has its own tools.
    ['create_entry_guide', [
        'describe_entry_schema',
        'unpublished drafts',
        'position',
        'create_nested_entry',
        'move_nested_entry',
        'postDate',
        'expiryDate',
        'craft://guides/content-writing',
    ]],
    // A batch is a read-modify-write loop, so it is the one flow that needs
    // the optimistic-concurrency guard and the per-block escape hatch.
    ['bulk_entry_operations', [
        'count_entries',
        'expectedDateUpdated',
        'create_nested_entry',
        'move_nested_entry',
        'publish_entry',
    ]],
    // Every key named here is one list_drafts actually returns.
    ['review_pending_drafts', [
        'list_drafts',
        'draftElementId',
        'canonicalId',
        'isNewEntry',
        'publish_entry',
        'delete_entry',
    ]],
    // list_entries defaults to live only while count_entries counts every
    // status: the asymmetry is a footgun worth naming.
    ['query_entries_guide', [
        'count_entries',
        'relatedTo',
        'status',
    ]],
    ['explore_section_schema', [
        'describe_entry_schema',
    ]],
]);

// Every tool a prompt names has to exist. A prompt is the one place a tool
// rename does not break loudly: the text still reads fine and the agent calls
// something that is gone.
it('names only tools this server still ships', function () {
    $shipped = [];
    foreach (glob(dirname(__DIR__, 3) . '/src/tools/*.php') as $file) {
        preg_match_all("/name: '([a-z_]+)'/", (string) file_get_contents($file), $declared);
        $shipped = [...$shipped, ...$declared[1]];
    }

    // Only tokens opening with a tool verb, so response keys and prose keep out.
    $verbs = 'get|list|count|create|update|delete|publish|duplicate|copy|move'
        . '|describe|read|run|explain|query|execute|clear|reload|tinker';
    $unknown = [];

    foreach (($this->promptBodies)() as $prompt => $body) {
        preg_match_all("/\b((?:{$verbs})_[a-z_]+)\b/", $body, $named);

        foreach (array_unique($named[1]) as $token) {
            if (!in_array($token, $shipped, true)) {
                $unknown[] = "{$prompt}: {$token}";
            }
        }
    }

    expect($unknown)->toBe([]);
});
