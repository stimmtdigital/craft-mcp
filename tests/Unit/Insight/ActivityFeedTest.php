<?php

declare(strict_types=1);

use stimmt\craft\Mcp\insight\ActivityFeed;

$revision = static fn (array $overrides = []): array => [
    'entryId' => 10,
    'title' => 'Hello',
    'section' => 'blog',
    'site' => 'default',
    'revisionNum' => 3,
    'creatorId' => 5,
    'date' => new DateTimeImmutable('2026-11-15T10:00:00+00:00'),
    'notes' => 'Fixed typo',
    'cpEditUrl' => 'https://example.test/admin/entries/blog/10-hello?revisionId=99',
    ...$overrides,
];

$deleted = static fn (array $overrides = []): array => [
    'entryId' => 11,
    'title' => 'Gone',
    'section' => 'blog',
    'site' => 'default',
    'date' => new DateTimeImmutable('2026-11-16T09:00:00+00:00'),
    'cpEditUrl' => null,
    ...$overrides,
];

describe('ActivityFeed::kind()', function () {
    it('reads revision 1 as the creation and every later one as an update', function (int $num, string $kind) {
        expect(ActivityFeed::kind($num))->toBe($kind);
    })->with([[1, 'created'], [2, 'updated'], [40, 'updated']]);
});

describe('ActivityFeed::revisionRow()', function () use ($revision) {
    it('shapes a revision with its creator named', function () use ($revision) {
        expect(ActivityFeed::revisionRow($revision(), [5 => 'ann']))->toBe([
            'entryId' => 10,
            'title' => 'Hello',
            'section' => 'blog',
            'site' => 'default',
            'date' => '2026-11-15T10:00:00+00:00',
            'cpEditUrl' => 'https://example.test/admin/entries/blog/10-hello?revisionId=99',
            'revisionNum' => 3,
            'changedBy' => ['id' => 5, 'username' => 'ann'],
            'notes' => 'Fixed typo',
            'kind' => 'updated',
        ]);
    });

    it('keeps a deleted creator\'s id without a username', function () use ($revision) {
        expect(ActivityFeed::revisionRow($revision(), [])['changedBy'])->toBe(['id' => 5, 'username' => null]);
    });

    it('names nobody when the revision has no creator (a console or queue save)', function () use ($revision) {
        expect(ActivityFeed::revisionRow($revision(['creatorId' => null]), [5 => 'ann'])['changedBy'])->toBeNull();
    });

    it('marks the first revision as the creation', function () use ($revision) {
        expect(ActivityFeed::revisionRow($revision(['revisionNum' => 1, 'notes' => '']), [])['kind'])->toBe('created');
    });

    it('reads empty notes as none', function () use ($revision) {
        expect(ActivityFeed::revisionRow($revision(['notes' => '']), [])['notes'])->toBeNull();
    });
});

describe('ActivityFeed::deletedRow()', function () use ($deleted) {
    it('shapes a deletion with nobody named, because Craft records nobody', function () use ($deleted) {
        expect(ActivityFeed::deletedRow($deleted()))->toBe([
            'entryId' => 11,
            'title' => 'Gone',
            'section' => 'blog',
            'site' => 'default',
            'date' => '2026-11-16T09:00:00+00:00',
            'cpEditUrl' => null,
            'revisionNum' => null,
            'changedBy' => null,
            'notes' => null,
            'kind' => 'deleted',
        ]);
    });
});

describe('ActivityFeed::page()', function () use ($revision, $deleted) {
    $rows = static fn () => [
        [
            ActivityFeed::revisionRow($revision(['entryId' => 1, 'date' => new DateTimeImmutable('2026-11-15T10:00:00+00:00')]), []),
            ActivityFeed::revisionRow($revision(['entryId' => 2, 'date' => new DateTimeImmutable('2026-11-13T10:00:00+00:00')]), []),
        ],
        [
            ActivityFeed::deletedRow($deleted(['entryId' => 3, 'date' => new DateTimeImmutable('2026-11-16T10:00:00+00:00')])),
            // Earlier than entry 1 once the offset is applied, though its local clock reads later.
            ActivityFeed::deletedRow($deleted(['entryId' => 4, 'date' => new DateTimeImmutable('2026-11-15T11:00:00+02:00')])),
        ],
    ];

    it('merges both sources newest first, comparing instants rather than clock text', function () use ($rows) {
        [$revisions, $deletions] = $rows();

        expect(array_column(ActivityFeed::page($revisions, $deletions, 10, 0), 'entryId'))->toBe([3, 1, 4, 2]);
    });

    it('cuts the page from the merged order', function () use ($rows) {
        [$revisions, $deletions] = $rows();

        expect(array_column(ActivityFeed::page($revisions, $deletions, 2, 1), 'entryId'))->toBe([1, 4]);
    });
});
