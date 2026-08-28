<?php

declare(strict_types=1);

use Mcp\Schema\Content\TextContent;
use stimmt\craft\Mcp\support\Payload;

describe('Payload::overBudget()', function () {
    it('says nothing about a result that fits', function () {
        expect(Payload::overBudget([new TextContent('small')], 1048576))->toBeNull();
    });

    it('names the size, the limit, and what to do about it', function () {
        $refusal = Payload::overBudget([new TextContent(str_repeat('x', 2 * 1048576))], 1048576);

        expect($refusal)->toBeString()
            ->and($refusal)->toContain('MB')
            ->and($refusal)->toContain('lower limit')
            ->and($refusal)->toContain('maxResponseBytes');
    });

    it('is switched off by a budget of zero', function () {
        expect(Payload::overBudget([new TextContent(str_repeat('x', 4 * 1048576))], 0))->toBeNull();
    });

    it('reports kilobytes below a megabyte and megabytes above it', function (int $size, string $unit) {
        expect(Payload::overBudget([new TextContent(str_repeat('x', $size))], 1024))
            ->toContain($unit);
    })->with([
        'kilobytes' => [4096, 'KB'],
        'megabytes' => [2 * 1048576, 'MB'],
    ]);
});
