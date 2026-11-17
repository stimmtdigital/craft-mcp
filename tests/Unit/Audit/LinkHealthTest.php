<?php

declare(strict_types=1);

use stimmt\craft\Mcp\audit\BrokenReason;
use stimmt\craft\Mcp\audit\LinkHealth;
use stimmt\craft\Mcp\audit\TargetState;

describe('LinkHealth', function () {
    it('calls a target that was not found missing', function () {
        expect(LinkHealth::reason(null))->toBe(BrokenReason::Missing);
    });

    it('names the reason a found target is not live', function (TargetState $state, BrokenReason $reason) {
        expect(LinkHealth::reason($state))->toBe($reason);
    })->with([
        'in the trash' => [new TargetState(deleted: true, draft: false, status: 'live'), BrokenReason::Deleted],
        'a draft' => [new TargetState(deleted: false, draft: true, status: 'live'), BrokenReason::DraftOnly],
        'disabled' => [new TargetState(deleted: false, draft: false, status: 'disabled'), BrokenReason::Disabled],
        'archived' => [new TargetState(deleted: false, draft: false, status: 'archived'), BrokenReason::Disabled],
        'pending' => [new TargetState(deleted: false, draft: false, status: 'pending'), BrokenReason::Pending],
        'expired' => [new TargetState(deleted: false, draft: false, status: 'expired'), BrokenReason::Expired],
    ]);

    // The reason is the first thing an editor has to fix: restore before
    // anything else, publish the draft before its enabled flag matters.
    it('reports the reason to fix first when several hold', function (TargetState $state, BrokenReason $reason) {
        expect(LinkHealth::reason($state))->toBe($reason);
    })->with([
        'trashed and disabled' => [new TargetState(true, false, 'disabled'), BrokenReason::Deleted],
        'trashed draft' => [new TargetState(true, true, 'live'), BrokenReason::Deleted],
        'disabled draft' => [new TargetState(false, true, 'disabled'), BrokenReason::DraftOnly],
    ]);

    it('passes a live target', function (?string $status) {
        expect(LinkHealth::reason(new TargetState(false, false, $status)))->toBeNull();
    })->with([
        'a live entry' => ['live'],
        'an enabled asset or category' => ['enabled'],
        'no status' => [null],
        // A plugin's own vocabulary is not evidence of a broken link.
        'a status core does not define' => ['onSale'],
    ]);
});
