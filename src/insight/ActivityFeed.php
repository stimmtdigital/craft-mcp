<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

use DateTimeInterface;

/**
 * Rows of the recent-changes feed, and how the two sources merge into one.
 *
 * A row comes from one of two places. A revision: Craft writes one each time
 * a canonical entry is saved (craft\services\Revisions::createRevision), with
 * the saving user as its creator and the save's moment as its dateCreated. Or
 * a soft-deleted entry, whose elements.dateDeleted falls in the window; Craft
 * keeps no record of who deleted it, so that row names nobody.
 */
final class ActivityFeed {
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string DELETED = 'deleted';

    /**
     * Revision 1 is the entry's first save. It can also be the first save
     * after versioning was switched on for an older entry, which is why the
     * row carries revisionNum as well: the kind is the likely reading, the
     * number is the fact.
     */
    public static function kind(int $revisionNum): string {
        return $revisionNum <= 1 ? self::CREATED : self::UPDATED;
    }

    /**
     * @param array{entryId: int, title: string|null, section: string|null, site: string|null, revisionNum: int, creatorId: int|null, date: DateTimeInterface, notes: string|null, cpEditUrl: string|null} $facts
     * @param array<int, string> $usernames Creator id => username, for the creators that still exist
     * @return array<string, mixed>
     */
    public static function revisionRow(array $facts, array $usernames): array {
        return [
            ...self::common($facts),
            'revisionNum' => $facts['revisionNum'],
            'changedBy' => self::changedBy($facts['creatorId'], $usernames),
            'notes' => $facts['notes'] === '' ? null : $facts['notes'],
            'kind' => self::kind($facts['revisionNum']),
        ];
    }

    /**
     * @param array{entryId: int, title: string|null, section: string|null, site: string|null, date: DateTimeInterface, cpEditUrl: string|null} $facts
     * @return array<string, mixed>
     */
    public static function deletedRow(array $facts): array {
        return [
            ...self::common($facts),
            'revisionNum' => null,
            'changedBy' => null,
            'notes' => null,
            'kind' => self::DELETED,
        ];
    }

    /**
     * One page of the two sources together, newest first.
     *
     * WHY each source is read up to offset + limit rather than paged on its
     * own: an offset into the merged feed says nothing about how far into
     * either source it reaches, so the only page that is certainly right is
     * cut from the first offset + limit rows of each.
     *
     * @param list<array<string, mixed>> $revisions
     * @param list<array<string, mixed>> $deletions
     * @return list<array<string, mixed>>
     */
    public static function page(array $revisions, array $deletions, int $limit, int $offset): array {
        $rows = [...$revisions, ...$deletions];
        $instant = static fn (array $row): int => (int) strtotime((string) $row['date']);
        usort($rows, static fn (array $a, array $b): int => $instant($b) <=> $instant($a));

        return array_slice($rows, $offset, $limit);
    }

    /**
     * @param array{entryId: int, title: string|null, section: string|null, site: string|null, date: DateTimeInterface, cpEditUrl: string|null} $facts
     * @return array<string, mixed>
     */
    private static function common(array $facts): array {
        return [
            'entryId' => $facts['entryId'],
            'title' => $facts['title'],
            'section' => $facts['section'],
            'site' => $facts['site'],
            'date' => $facts['date']->format(DateTimeInterface::ATOM),
            'cpEditUrl' => $facts['cpEditUrl'],
        ];
    }

    /**
     * A creator whose account was since deleted keeps the id with no
     * username, rather than vanishing: that someone changed it is still true.
     *
     * @param array<int, string> $usernames
     * @return array{id: int, username: string|null}|null
     */
    private static function changedBy(?int $creatorId, array $usernames): ?array {
        return $creatorId === null ? null : ['id' => $creatorId, 'username' => $usernames[$creatorId] ?? null];
    }
}
