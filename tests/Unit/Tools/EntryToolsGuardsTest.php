<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use stimmt\craft\Mcp\capabilities\EntryTools;

/**
 * The refusals stimmt_entries_count answers with instead of a plausible wrong
 * result: "0" for a section, type or status that does not exist.
 *
 * Helpers are closures because Pest shares one global function namespace
 * across the suite, and the source-reading helpers here would collide with
 * the ones other entry tests keep for themselves.
 */
$entryToolsSource = static fn (): string => (string) file_get_contents(
    (string) (new ReflectionClass(EntryTools::class))->getFileName(),
);

/** The body of one method, for the assertions that are about ordering. */
$methodBody = static function (string $method) use ($entryToolsSource): string {
    $reflection = new ReflectionMethod(EntryTools::class, $method);

    return implode("\n", array_slice(
        explode("\n", $entryToolsSource()),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

describe('unknown section, type and status', function () use ($entryToolsSource, $methodBody) {
    // "How many entries in section X" answering 0 is a wrong answer when the
    // truthful one is "there is no X". The refusals live in HandleResolver,
    // which every surface asking about a handle shares; HandleResolverTest
    // owns their wording. What stays here is that the count asks, and asks
    // before it builds a query.
    it('validates section, type and status before it builds the query', function () use ($methodBody) {
        $body = $methodBody('countEntries');
        $query = (int) strpos($body, 'Entry::find()');

        foreach (['HandleResolver::section($section)', 'HandleResolver::entryType($type', 'HandleResolver::entryStatus($status)'] as $guard) {
            expect(strpos($body, $guard))->toBeInt()
                ->and(strpos($body, $guard))->toBeLessThan($query);
        }
    });

    it('leaves the handle vocabulary to the shared resolver', function () use ($entryToolsSource) {
        expect($entryToolsSource())->toContain('HandleResolver::section($section)')
            ->and($entryToolsSource())->not->toContain('getSectionByHandle');
    });
});
