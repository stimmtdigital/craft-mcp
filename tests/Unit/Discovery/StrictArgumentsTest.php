<?php

declare(strict_types=1);

use stimmt\craft\Mcp\discovery\Loader;

/**
 * Unknown arguments are refused, for every tool, at the wire.
 *
 * JSON Schema allows undeclared properties unless a schema says otherwise, and
 * the SDK builds the top level as type, properties and required without ever
 * saying otherwise. So a misspelled parameter was dropped in silence and the
 * tool answered as though it had never been passed: count_entries with
 * `sectionHandle` counted every entry in the install and reported that as
 * confidently as the spelling that works.
 *
 * Asserted on the loader rather than tool by tool, because the point of fixing
 * it there is that a new tool cannot be added without it.
 */
$body = static function (string $method): string {
    $reflection = new ReflectionMethod(Loader::class, $method);
    $lines = explode("\n", (string) file_get_contents((string) $reflection->getFileName()));

    return implode("\n", array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

describe('tool arguments', function () use ($body) {
    it('refuses what no parameter declares', function () use ($body) {
        expect($body('rebuild'))->toContain("'additionalProperties' => false");
    });

    // Both paths, because a locked tool still has to refuse a typo rather than
    // accept one silently on its way to the upgrade notice.
    it('holds on every path a tool reaches the registry by', function (string $method) use ($body) {
        expect($body($method))->toContain('rebuild(');
    })->with(['strict', 'inert']);

    it('registers through the strict path, not the raw discovered tool', function () use ($body) {
        expect($body('loadTools'))->toContain('$this->strict($reference->tool)')
            ->and($body('loadTools'))->not->toContain('registerTool($reference->tool,');
    });
});
