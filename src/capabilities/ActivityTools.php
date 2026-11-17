<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\behaviors\RevisionBehavior;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\models\Section;
use DateTime;
use DateTimeInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\insight\ActivityFeed;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\Window;
use Throwable;

/**
 * Who changed what, across every section, read off Craft's own history.
 */
final class ActivityTools {
    /**
     * The deepest the feed reads into either source. A merged page has to be
     * cut from the first offset + limit rows of each (ActivityFeed::page), so
     * this caps the rows one call loads, whatever the caller pages to.
     */
    public const int MAX_SCAN = 500;

    private const string DEFAULT_SINCE = '-7 days';

    #[McpTool(
        name: 'stimmt_activity_recent',
        title: 'Recent changes',
        description: 'A newest-first feed of entry changes across every section: who saved or deleted which entry, and when. Built from Craft\'s revisions (one per save of a live entry, kind "created" for revision 1, "updated" after) plus soft-deleted entries (kind "deleted", changedBy null because Craft does not record who deleted). Only sections the connected user may view appear. Sections with versioning switched off, unsaved drafts, and revisions Craft has already pruned (maxRevisions) do not appear. offset + limit may not exceed ' . self::MAX_SCAN . '.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    public function recentActivity(
        #[Schema(description: 'Earliest change to include: any date Craft parses, such as "2026-11-01", "2026-11-01T09:00:00+01:00" or "-3 days". Defaults to 7 days ago.')]
        ?string $since = null,
        #[Schema(description: 'Section handle to narrow the feed to. Omit for every section the user may view.')]
        ?string $section = null,
        #[Schema(description: 'Username or email of the user whose saves to list. Deletions are left out when this is set, because Craft does not record who deleted an entry.')]
        ?string $author = null,
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 50,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        $this->assertScan($limit, $offset);
        $from = $this->since($since);
        $sectionModel = HandleResolver::section($section);
        $creator = $this->author($author);

        $revisions = $this->revisionQuery($from, $sectionModel, $creator);
        // Nobody is recorded as a deletion's author, so asking for one user's
        // changes cannot honestly include any.
        $deletions = $creator === null ? $this->deletionQuery($from, $sectionModel) : null;

        $scan = $offset + $limit;
        $revisionRows = $this->revisionRows((clone $revisions)->limit($scan)->all());
        $deletionRows = $deletions === null ? [] : array_values(array_filter(array_map(
            $this->deletionRow(...),
            (clone $deletions)->limit($scan)->all(),
        )));

        // Counted after scoping, so the total is what this caller may see.
        $total = (int) $revisions->count() + (int) ($deletions?->count() ?? 0);
        $page = ActivityFeed::page($revisionRows, $deletionRows, $limit, $offset);

        return Budget::assert([
            'since' => $from->format(DateTimeInterface::ATOM),
            ...Response::paginated('changes', $page, $total, $limit, $offset),
        ]);
    }

    private function assertScan(int $limit, int $offset): void {
        if ($offset + $limit > self::MAX_SCAN) {
            throw new ToolCallException(
                'offset + limit may not exceed ' . self::MAX_SCAN . ", got {$offset} + {$limit}."
                . ' Narrow the window instead: a later since, a section, or an author.',
            );
        }
    }

    private function since(?string $since): DateTime {
        $value = $since === null || trim($since) === '' ? self::DEFAULT_SINCE : $since;
        $parsed = DateTimeHelper::toDateTime($value);

        return $parsed === false
            ? throw new ToolCallException(
                "Could not read '{$value}' as a date for since."
                . ' Use a date such as "2026-11-01" or a relative one such as "-3 days".',
            )
            : $parsed;
    }

    /**
     * WHY a user unknown by that name is refused: Craft would answer a
     * creator filter that matches nobody with no rows, which reads as "this
     * person changed nothing" when the question never reached the history.
     */
    private function author(?string $author): ?User {
        if ($author === null || trim($author) === '') {
            return null;
        }

        return Craft::$app->getUsers()->getUserByUsernameOrEmail($author)
            ?? throw new ToolCallException("No user has the username or email '{$author}'. Check the spelling.");
    }

    /**
     * Revisions of section entries only: section('*') leaves out nested
     * Matrix entries, which are duplicated along with every owner save and
     * would bury the owner's one row under its blocks.
     */
    private function revisionQuery(DateTime $from, ?Section $section, ?User $creator): EntryQuery {
        $query = Entry::find()
            ->revisions()
            ->section($section ?? '*')
            ->site('*')
            ->unique()
            ->status(null)
            ->dateCreated('>= ' . $from->format(DateTimeInterface::ATOM))
            ->orderBy(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($creator !== null) {
            $query->revisionCreator($creator);
        }

        Authorization::scopeQuery($query);

        return $query;
    }

    private function deletionQuery(DateTime $from, ?Section $section): EntryQuery {
        $query = Entry::find()
            ->trashed()
            ->section($section ?? '*')
            ->site('*')
            ->unique()
            ->status(null)
            ->andWhere(['>=', 'elements.dateDeleted', Db::prepareDateForDb($from)])
            ->orderBy(['elements.dateDeleted' => SORT_DESC, 'elements.id' => SORT_DESC]);

        Authorization::scopeQuery($query);

        return $query;
    }

    /**
     * @param Entry[] $entries
     * @return list<array<string, mixed>>
     */
    private function revisionRows(array $entries): array {
        $facts = array_values(array_filter(array_map($this->revisionFacts(...), $entries)));
        $usernames = $this->usernames(array_column($facts, 'creatorId'));

        return array_map(static fn (array $row): array => ActivityFeed::revisionRow($row, $usernames), $facts);
    }

    /**
     * Null for a row the query returned without its revision data, which
     * cannot be placed in the feed.
     *
     * @return array{entryId: int, title: string|null, section: string|null, site: string|null, revisionNum: int, creatorId: int|null, date: DateTimeInterface, notes: string|null, cpEditUrl: string|null}|null
     */
    private function revisionFacts(Entry $entry): ?array {
        $revision = $entry->getBehavior('revision');
        if (!$revision instanceof RevisionBehavior || $entry->dateCreated === null) {
            return null;
        }

        return [
            'entryId' => (int) $entry->getCanonicalId(),
            'title' => $entry->title,
            'section' => $entry->getSection()?->handle,
            'site' => $this->siteHandle($entry),
            'revisionNum' => $revision->revisionNum,
            'creatorId' => $revision->creatorId,
            'date' => $entry->dateCreated,
            'notes' => $revision->revisionNotes,
            // The revision's own URL: it opens the entry at that save, which is
            // what a reader of a change feed wants to look at.
            'cpEditUrl' => $entry->getCpEditUrl(),
        ];
    }

    /**
     * Null for a trashed row without its deletion date, which cannot be
     * placed in the feed and is not given an invented one.
     *
     * @return array<string, mixed>|null
     */
    private function deletionRow(Entry $entry): ?array {
        if ($entry->dateDeleted === null) {
            return null;
        }

        return ActivityFeed::deletedRow([
            'entryId' => (int) $entry->id,
            'title' => $entry->title,
            'section' => $entry->getSection()?->handle,
            'site' => $this->siteHandle($entry),
            'date' => $entry->dateDeleted,
            // A trashed entry has no editor page; it is restored from the
            // section's index with the "Trashed" status filter.
            'cpEditUrl' => null,
        ]);
    }

    /**
     * Usernames of the creators, read in one query. Not scoped by the
     * viewUsers permission on purpose: the control panel shows a revision's
     * creator to anyone who may view the entry, and this is that same name.
     *
     * @param array<int|null> $ids
     * @return array<int, string>
     */
    private function usernames(array $ids): array {
        $ids = array_values(array_unique(array_filter($ids, is_int(...))));
        if ($ids === []) {
            return [];
        }

        $usernames = [];
        foreach (User::find()->id($ids)->status(null)->all() as $user) {
            $usernames[(int) $user->id] = (string) $user->username;
        }

        return $usernames;
    }

    /**
     * An element whose site was deleted throws from getSite(); the change
     * still happened, so the row keeps its place without a site.
     */
    private function siteHandle(Entry $entry): ?string {
        try {
            return $entry->getSite()->handle;
        } catch (Throwable) {
            return null;
        }
    }
}
