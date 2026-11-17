<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use craft\base\FieldInterface;
use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use craft\models\Site;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\Lookup;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\review\BlockAliases;
use stimmt\craft\Mcp\review\DraftToReview;
use stimmt\craft\Mcp\review\EntryState;
use stimmt\craft\Mcp\review\FieldChanges;
use stimmt\craft\Mcp\review\PerSiteFields;
use stimmt\craft\Mcp\review\Rekey;
use stimmt\craft\Mcp\review\TranslationGap;
use stimmt\craft\Mcp\review\TranslationScope;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\Window;

/**
 * Content review before it goes out: what a draft changes, and what a site
 * still has to translate.
 *
 * Both tools are reads that load elements and hand plain Reader payloads to
 * the comparison classes under src/review, which hold every decision about
 * what counts as a change or a gap and are tested without an install.
 */
final readonly class ReviewTools {
    private const int DEFAULT_SCAN = 50;

    private Reader $reader;

    private FieldChanges $changes;

    private EntryState $state;

    public function __construct(?Reader $reader = null, ?FieldChanges $changes = null, ?EntryState $state = null) {
        $this->reader = $reader ?? ElementModule::reader();
        $this->changes = $changes ?? new FieldChanges();
        $this->state = $state ?? new EntryState();
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stimmt_drafts_diff',
        title: 'What a draft changes',
        description: 'Before publishing, show field by field what a draft changes compared with its live (canonical) entry: title, slug, entry type, enabled, enabled for the site, post and expiry date, structure parent, and every custom field. Each change reads {field, label, kind: added|removed|changed, before, after}; text over 500 characters is cut and flagged truncated. Matrix fields report per block under "blocks" (added, removed, moved or changed blocks by block id and type) and ContentBlock fields report their own fields under "changes". Relations read as natural keys. A draft of an entry that was never published has no live entry: newEntry is true and every non-empty value reads as added. Takes the same id stimmt_drafts_publish does; revisions and provisional (autosaved) drafts are refused.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    public function diffDraft(
        #[Schema(description: 'A draft element id, or a canonical entry id that has exactly one pending draft.')]
        int $id,
        #[Schema(description: 'Site handle to compare on. Omit for the primary site.')]
        ?string $site = null,
    ): array {
        $draft = DraftToReview::resolve($id, SiteResolver::resolve($site));
        Authorization::assertCanView($draft);
        $canonical = $this->liveVersion($draft);
        $siteHandle = $draft->getSite()->handle;

        $attributes = $this->changes->between(
            $canonical === null ? [] : $this->state->of($canonical),
            $this->state->of($draft, $canonical),
            EntryState::LABELS,
        );
        $fields = $this->changes->between(
            $canonical === null ? [] : $this->fieldValues($canonical, $siteHandle),
            Rekey::fields($this->fieldValues($draft, $siteHandle), BlockAliases::of($draft)),
            $this->labels($draft, $canonical),
        );

        return Budget::assert([
            'site' => $siteHandle,
            'newEntry' => $canonical === null,
            'draft' => $this->describeDraft($draft),
            'canonical' => $canonical === null ? null : $this->describeCanonical($canonical),
            'changes' => [...$attributes['changes'], ...$fields['changes']],
            'unchangedCount' => $attributes['unchangedCount'] + $fields['unchangedCount'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stimmt_entries_untranslated',
        title: 'Translation gaps',
        description: 'On a multi-site install, list entries whose content on a target site is still untranslated: a value the source site has that the target site leaves empty ("empty") or holds unchanged ("same-as-source"). Only values the two sites keep apart are checked: custom fields Craft translates per site for these two sites, plus title and slug when the entry type translates them. Values empty on the source are ignored. Relations compare by the elements they point at; Matrix blocks by type and content. limit bounds the entries SCANNED, not the entries returned, so one call never walks the whole install: total counts the gaps found in this window, scanned the entries looked at, and hasMore says whether to call again at offset + scanned. An entry that does not exist on the target site at all reads missingOnSite: true.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    public function untranslatedEntries(
        #[Schema(description: 'Target site handle: the site whose translations to check.')]
        string $site,
        #[Schema(description: 'Section handle to scan. Omit to scan every section enabled on both sites.')]
        ?string $section = null,
        #[Schema(description: 'Site handle to compare against. Omit for the primary site.')]
        ?string $sourceSite = null,
        #[Schema(description: 'How many source-site entries to scan, not how many to return. 1 or greater.', minimum: Window::MIN_LIMIT)]
        int $limit = self::DEFAULT_SCAN,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        [$source, $target] = TranslationScope::sites($sourceSite, $site);
        $sectionIds = TranslationScope::sectionIds(HandleResolver::section($section), $source, $target);

        $query = Entry::find()->site($source)->sectionId($sectionIds)->status(null)->orderBy(['elements.id' => SORT_ASC]);
        Authorization::scopeQuery($query);
        $scanned = (clone $query)->limit($limit)->offset($offset)->all();
        $available = (int) $query->count();

        $rows = $this->gapRows($scanned, $this->onSite($scanned, $target), (string) $source->handle);

        // The standard envelope, with hasMore corrected: limit bounds the
        // entries scanned, so whether to call again depends on how many were
        // scanned, not on how many happened to have gaps.
        return Budget::assert([
            ...Response::paginated('entries', $rows, count($rows), $limit, $offset),
            'site' => $target->handle,
            'sourceSite' => $source->handle,
            'scanned' => count($scanned),
            'hasMore' => $offset + count($scanned) < $available,
        ]);
    }

    /**
     * The live entry a draft would be applied to, or null for a draft that
     * has never been published and so has none.
     */
    private function liveVersion(Entry $draft): ?Entry {
        if ($draft->getIsUnpublishedDraft()) {
            return null;
        }

        $canonicalId = (int) $draft->getCanonicalId();
        $site = $draft->getSite();
        $canonical = Lookup::canonical($canonicalId, $site) ?? throw new ToolCallException(
            "Draft {$draft->id}'s live entry {$canonicalId} was not found on site '{$site->handle}'.",
        );
        Authorization::assertCanView($canonical);

        return $canonical;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldValues(Entry $entry, string $siteHandle): array {
        return $this->reader->readFields($entry, array_keys(LayoutFields::of($entry->getFieldLayout())), $siteHandle);
    }

    /**
     * Field names by handle, the draft's layout first: a field the draft's
     * entry type dropped still needs a name to be reported as removed.
     *
     * @return array<string, string>
     */
    private function labels(Entry $draft, ?Entry $canonical): array {
        $fields = LayoutFields::of($draft->getFieldLayout()) + LayoutFields::of($canonical?->getFieldLayout());

        return array_map(static fn (FieldInterface $field): string => (string) ($field->name ?? $field->handle), $fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDraft(Entry $draft): array {
        $behavior = $draft->getBehavior('draft');
        $draftInfo = $behavior instanceof DraftBehavior ? $behavior : null;

        return [
            'id' => (int) $draft->id,
            'name' => $draftInfo?->draftName,
            'notes' => $draftInfo?->draftNotes,
            'creator' => $draftInfo?->getCreator()?->username,
            'dateUpdated' => $draft->dateUpdated?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeCanonical(Entry $canonical): array {
        return ['id' => (int) $canonical->id, 'title' => $canonical->title, 'status' => $canonical->getStatus()];
    }

    /**
     * The scanned entries as they exist on the target site, by id, in one
     * query rather than one per entry.
     *
     * @param Entry[] $entries
     * @return array<int, Entry>
     */
    private function onSite(array $entries, Site $site): array {
        if ($entries === []) {
            return [];
        }

        $query = Entry::find()->id(array_map(static fn (Entry $entry): int => (int) $entry->id, $entries))
            ->site($site)->status(null)->indexBy('id');
        Authorization::scopeQuery($query);

        return $query->all();
    }

    /**
     * @param Entry[] $entries on the source site
     * @param array<int, Entry> $targets the same entries on the target site, by id
     * @return list<array<string, mixed>>
     */
    private function gapRows(array $entries, array $targets, string $sourceHandle): array {
        $rows = [];
        foreach ($entries as $entry) {
            $target = $targets[(int) $entry->id] ?? null;
            $gaps = $target === null ? null : $this->gaps($entry, $target, $sourceHandle);
            if ($gaps === []) {
                continue;
            }

            $rows[] = [
                'id' => (int) $entry->id,
                'title' => $entry->title,
                'section' => $entry->getSection()?->handle,
                ...($gaps === null ? ['missingOnSite' => true, 'fields' => []] : ['fields' => $gaps]),
            ];
        }

        return $rows;
    }

    /**
     * WHY the target is read in the SOURCE site's terms: relations read as
     * natural keys, and a related entry's slug may itself be translated. Read
     * per site, the same related entry would carry two different slugs and an
     * untranslated relation would look translated.
     *
     * @return list<array{field: string, reason: string}>
     */
    private function gaps(Entry $source, Entry $target, string $sourceHandle): array {
        $handles = PerSiteFields::fieldHandles($source, $target);
        $attributes = PerSiteFields::attributeHandles($source, $target);

        return TranslationGap::ofEntry(
            [...$this->attributeValues($source, $attributes), ...$this->reader->readFields($source, $handles, $sourceHandle)],
            [...$this->attributeValues($target, $attributes), ...$this->reader->readFields($target, $handles, $sourceHandle)],
            [...$attributes, ...$handles],
        );
    }

    /**
     * @param list<string> $attributes
     * @return array<string, mixed>
     */
    private function attributeValues(Entry $entry, array $attributes): array {
        return array_intersect_key(['title' => $entry->title, 'slug' => $entry->slug], array_flip($attributes));
    }
}
