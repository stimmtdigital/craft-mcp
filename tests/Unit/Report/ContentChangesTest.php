<?php

declare(strict_types=1);

use stimmt\craft\Mcp\report\ContentChanges;

describe('ContentChanges::aggregate()', function () {
    $sections = [1 => 'news', 2 => 'pages'];
    $editors = [10 => 'anna', 11 => 'bram'];

    it('counts saves and first versions per section and per editor, busiest first', function () use ($sections, $editors) {
        $result = ContentChanges::aggregate([
            ['sectionId' => 1, 'creatorId' => 10, 'num' => 1],
            ['sectionId' => 1, 'creatorId' => 10, 'num' => 2],
            ['sectionId' => 1, 'creatorId' => 11, 'num' => 3],
            ['sectionId' => 2, 'creatorId' => 11, 'num' => 1],
        ], $sections, $editors);

        expect($result['saves'])->toBe(4)
            ->and($result['created'])->toBe(2)
            ->and($result['bySection'])->toBe([
                ['section' => 'news', 'saves' => 3, 'created' => 1],
                ['section' => 'pages', 'saves' => 1, 'created' => 1],
            ])
            ->and($result['byEditor'])->toBe([
                ['editor' => 'anna', 'saves' => 2],
                ['editor' => 'bram', 'saves' => 2],
            ]);
    });

    // A save by a console script or migration has no creator, and a creator
    // may since have been deleted: both are still changes and must be counted.
    it('names saves without a creator and saves by deleted users instead of dropping them', function () use ($sections, $editors) {
        $result = ContentChanges::aggregate([
            ['sectionId' => 1, 'creatorId' => null, 'num' => 2],
            ['sectionId' => 1, 'creatorId' => 99, 'num' => 2],
            ['sectionId' => 7, 'creatorId' => 10, 'num' => 2],
        ], $sections, $editors);

        expect(array_column($result['byEditor'], 'editor'))->toEqualCanonicalizing(['(system)', '(deleted user)', 'anna'])
            ->and(array_column($result['bySection'], 'section'))->toEqualCanonicalizing(['news', '(unknown)'])
            ->and($result['saves'])->toBe(3);
    });

    it('reports zero rather than nothing for a quiet period', function () use ($sections, $editors) {
        expect(ContentChanges::aggregate([], $sections, $editors))
            ->toBe(['saves' => 0, 'created' => 0, 'bySection' => [], 'byEditor' => []]);
    });
});
