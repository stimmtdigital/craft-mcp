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
});
