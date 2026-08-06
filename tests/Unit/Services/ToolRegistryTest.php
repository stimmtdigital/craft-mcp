<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\Mcp;

// #57 sub-bug: by_source used to count tool CLASSES per source while total
// counts tools, so the summary did not sum. Both must count definitions.
it('sums by_source to total in getSummary()', function () {
    $summary = Mcp::getToolRegistry()->getSummary();

    expect(array_sum($summary['by_source']))->toBe($summary['total'])
        ->and(array_sum($summary['by_category']))->toBe($summary['total']);
});
