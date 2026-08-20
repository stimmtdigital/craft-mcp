<?php

declare(strict_types=1);

use stimmt\craft\Mcp\resources\GuideResources;

describe('GuideResources', function () {
    it('serves the shipped content-writing guide with the full contract', function () {
        $guide = (new GuideResources())->contentWriting();

        expect($guide)->toContain('{"section": "pages", "slug": "about"}')
            ->and($guide)->toContain('describe_entry_schema')
            ->and($guide)->toContain('publish_entry')
            ->and($guide)->toContain('list_drafts')
            ->and($guide)->toContain('warnings');
    });

    // The guide is the contract an agent is told to trust before it writes, so
    // every capability the write tools gained has to be in it. A tool change
    // that leaves the guide behind fails here rather than misleading an agent.
    it('documents the capabilities the write tools actually have', function () {
        $guide = (new GuideResources())->contentWriting();

        expect($guide)->toContain('position')
            ->and($guide)->toContain('create_nested_entry')
            ->and($guide)->toContain('move_nested_entry')
            ->and($guide)->toContain('expectedDateUpdated');
    });
});
