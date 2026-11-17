<?php

declare(strict_types=1);

use stimmt\craft\Mcp\audit\AssetReferences;
use stimmt\craft\Mcp\audit\CountedSources;
use stimmt\craft\Mcp\audit\RefTagParser;

describe('AssetReferences', function () {
    it('collects the assets tags name by id, once each, in id order', function () {
        $references = new AssetReferences();
        $references->addTags(RefTagParser::parse('{asset:45:url} {asset:7} {asset:45@2:url||x}'));

        expect($references->ids())->toBe([7, 45])
            ->and($references->count())->toBe(2);
    });

    it('accepts the class name in place of the asset handle', function () {
        $references = new AssetReferences();
        $references->addTags(RefTagParser::parse('{craft\\elements\\Asset:12} {\\craft\\elements\\Asset:13}'));

        expect($references->ids())->toBe([12, 13]);
    });

    // An entry or category tag never makes an asset used, even with the same
    // id; a non-numeric asset ref resolves to no particular asset.
    it('ignores tags that do not name an asset by id', function () {
        $references = new AssetReferences();
        $references->addTags(RefTagParser::parse('{entry:45} {category:46} {asset:logo} {asset:0} {assets:47}'));

        expect($references->ids())->toBe([])
            ->and($references->count())->toBe(0);
    });

    it('accumulates across content rows', function () {
        $references = new AssetReferences();
        $references->addTags(RefTagParser::parse('{asset:3}'));
        $references->addTags(RefTagParser::parse('{asset:1} {asset:3}'));

        expect($references->ids())->toBe([1, 3]);
    });
});

describe('CountedSources', function () {
    // Revisions and the trash do not keep an asset in use; canonical
    // elements and drafts (no condition on draftId) do.
    it('counts every referencing element except revisions and trashed ones', function () {
        expect(CountedSources::condition('s'))->toBe([
            'and',
            ['s.revisionId' => null],
            ['s.dateDeleted' => null],
        ]);
    });
});
