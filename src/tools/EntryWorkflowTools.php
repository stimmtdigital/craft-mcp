<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\base\ElementInterface;
use craft\behaviors\DraftBehavior;
use craft\behaviors\RevisionBehavior;
use craft\elements\Entry;
use craft\models\Site;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\Lookup;
use stimmt\craft\Mcp\elements\Reach;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\EntryResolver;
use stimmt\craft\Mcp\support\NestedPosition;
use stimmt\craft\Mcp\support\ResourceChangeNotifier;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\Window;
use stimmt\craft\Mcp\support\WriteParams;

/**
 * Entry workflow: the pending-drafts review queue, publish, delete, duplicate, copy to site.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryWorkflowTools {
    private readonly Reader $reader;

    private readonly Writer $writer;

    public function __construct(?Reader $reader = null, ?Writer $writer = null) {
        $this->reader = $reader ?? ElementModule::reader();
        $this->writer = $writer ?? ElementModule::writer();
    }

    #[McpTool(
        name: 'list_drafts',
        title: 'Draft review queue',
        description: 'List pending (non-provisional) entry drafts awaiting review, newest first. Filter by section, site, or creator username/email. Each row carries the draft element id publish_entry accepts and a cpEditUrl for human review.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listDrafts(
        #[Schema(description: 'Section handle to narrow the queue to; list_sections reports the handles.')]
        ?string $section = null,
        #[Schema(description: 'Site handle to narrow the queue to; list_sites reports the handles.')]
        ?string $site = null,
        #[Schema(description: 'Username or email address of the person who created the draft.')]
        ?string $creator = null,
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($site);
        Window::assert($limit, $offset);

        $query = Entry::find()
            ->drafts()
            ->provisionalDrafts(false)
            ->status(null)
            ->limit($limit)
            ->offset($offset)
            ->orderBy(['dateUpdated' => SORT_DESC]);

        foreach (['section' => $section, 'site' => $site] as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }

        if ($creator !== null) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($creator)
                ?? throw new ToolCallException("No user found for '{$creator}'");
            $query->draftCreator($user);
        }

        Authorization::scopeQuery($query);
        $drafts = array_map($this->draftSummary(...), $query->all());

        return Response::paginated('drafts', $drafts, (int) $query->count(), $limit, $offset);
    }

    #[McpTool(
        name: 'list_revisions',
        title: 'Entry history',
        description: 'List a canonical entry\'s saved revisions, newest first: who saved each one, when, and with what notes. Answers "when did this change and by whom". Read a revision\'s full content with get_entry using its revisionElementId; the canonical entry id always holds the current content. History belongs to the canonical entry, so a draftElementId from list_drafts is refused, naming the canonicalId to ask for instead.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listRevisions(
        #[Schema(description: 'Canonical entry id whose history to list. A draft or revision id is refused with the canonical id to ask for instead.')]
        int $id,
        #[Schema(description: 'Site handle whose revisions to list; list_sites reports the handles.')]
        ?string $site = null,
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        $this->canonical($id, SiteResolver::resolve($site), 'list_revisions');
        Window::assert($limit, $offset);

        $query = Entry::find()
            ->revisionOf($id)
            ->revisions()
            ->status(null)
            ->limit($limit)
            ->offset($offset)
            ->orderBy(['dateCreated' => SORT_DESC, 'revisions.num' => SORT_DESC]);
        if ($site !== null) {
            $query->site($site);
        }

        Authorization::scopeQuery($query);
        $revisions = array_map($this->revisionSummary(...), $query->all());

        return Response::paginated('revisions', $revisions, (int) $query->count(), $limit, $offset);
    }

    #[McpTool(
        name: 'publish_entry',
        title: 'Publish an entry',
        description: 'Publish an entry: applies a draft (by draft element id, or a canonical id with exactly one pending draft) to its canonical entry, or enables a disabled live entry.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function publishEntry(
        #[Schema(description: 'The draft element id to apply, or a canonical entry id that has exactly one pending draft (or none and is merely disabled).')]
        int $id,
        #[Schema(description: 'Site handle to locate the entry by and to report the result in. Publishing is not per-site: the draft is applied to every site it exists on, so this only selects which site\'s values come back.')]
        ?string $site = null,
        ?RequestContext $context = null,
    ): array {
        $entry = EntryResolver::writable($id, SiteResolver::resolve($site), 'publish_entry');
        Authorization::assertCanPublish($entry);

        if ($entry->getIsDraft()) {
            return $this->applyDraft($entry, $site, $context);
        }

        return $this->publishCanonical($entry, $site, $context);
    }

    #[McpTool(
        name: 'delete_entry',
        title: 'Move an entry to the trash',
        description: 'Soft-delete an entry (moves to trash, restorable in the control panel). Matrix-family blocks are entries too: pass a block\'s own id to delete just that block without touching its siblings.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function deleteEntry(
        #[Schema(description: 'Element id to trash: an entry, a draft (draftElementId, which discards the draft and leaves the canonical entry untouched), or a single Matrix block by its own entry id.')]
        int $id,
        #[Schema(description: 'Site handle to locate the entry by. Deleting is not per-site: the entry is trashed on EVERY site it exists on, so this cannot be used to remove one translation. To take an entry out of one site only, disable it there.')]
        ?string $site = null,
        ?RequestContext $context = null,
    ): array {
        $entry = EntryResolver::writable($id, SiteResolver::resolve($site), 'delete_entry');
        Authorization::assertCanDelete($entry);

        // Read before the delete, while the element still has site rows to
        // report. Afterwards the answer would need the trashed scope and would
        // be describing the aftermath rather than what the call did.
        $affected = Reach::of($entry);

        if (!Craft::$app->getElements()->deleteElement($entry)) {
            throw new ToolCallException('Failed to delete entry');
        }

        return Response::success(['deleted' => $id, 'affectedSites' => $affected, 'restorable' => true]);
    }

    #[McpTool(
        name: 'duplicate_entry',
        title: 'Duplicate an entry',
        description: 'Duplicate an entry as an unpublished draft. Optional title/slug overrides and a payload-format fields JSON for "like X but change these". In a structure section the copy keeps the original\'s place: same parent, immediately after it. The response reports that parent, null meaning top level or a section without a structure.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function duplicateEntry(
        #[Schema(description: 'Id of the entry to copy.')]
        int $id,
        #[Schema(description: 'Site handle to copy on; list_sites reports the handles.')]
        ?string $site = null,
        #[Schema(description: 'Title for the copy. Omit to keep the original\'s.')]
        ?string $title = null,
        #[Schema(description: 'Slug for the copy. Omit to let Craft derive a unique one.')]
        ?string $slug = null,
        #[Schema(description: 'Field values to change on the copy, as a JSON-encoded STRING (not a nested object) in the payload format. Only the handles present are overwritten; the rest are copied as they were.')]
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        // Canonical entries only, unchanged: what a copy should hold is the
        // published content, and a draft's pending values are not that. The id
        // a caller reaches for is often a draftElementId a write just handed
        // them, though, so the refusal names the entry to copy instead of
        // reporting the draft as missing.
        $entry = $this->canonical($id, SiteResolver::resolve($site), 'duplicate_entry');
        Authorization::assertCanDuplicate($entry);

        $attributes = array_filter(['title' => $title, 'slug' => $slug], static fn (?string $v): bool => $v !== null);
        $duplicate = Craft::$app->getElements()->duplicateElement($entry, $attributes, asUnpublishedDraft: true);

        $result = $fields === null
            ? null
            : $this->writer->update($duplicate, [], WriteParams::fieldsPayload($fields), WriteMode::Draft, $site);

        if ($result !== null && $result->isFailure()) {
            return Response::failure($result->toArray());
        }

        // The warnings ride along on success too. A fields payload naming a
        // relation that cannot be resolved is dropped rather than guessed,
        // and this was the one write path that then reported plain success,
        // so the caller could not tell a complete duplicate from a partial
        // one. Every other write surfaces them; this one now agrees.
        return Response::success([
            'entry' => $this->reader->read($duplicate, $site),
            'parent' => $this->structureParent($duplicate),
            'warnings' => $result === null ? [] : $result->toArray()['warnings'],
        ]);
    }

    #[McpTool(
        name: 'copy_entry_to_site',
        title: 'Copy an entry to another site',
        description: 'Copy the field values an entry keeps SEPARATELY PER SITE from one site to another, as a draft on the target site. Copies values; does not machine-translate. Title and slug are left alone so a translated title survives, and so is any field Craft does not treat as translatable, because that field already holds one shared value on every site. The response lists the handles actually copied; an empty list means this entry type keeps no field per site, so its fields already match on both sites, though their titles and slugs still may not. describe_entry_schema reports each field\'s translation method.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function copyEntryToSite(
        #[Schema(description: 'Entry id, which is the same id on every site the entry exists on.')]
        int $id,
        #[Schema(description: 'Site handle to read the field values from.')]
        string $fromSite,
        #[Schema(description: 'Site handle to write the draft on. The entry must already exist there, which means its section is enabled for that site.')]
        string $toSite,
        ?RequestContext $context = null,
    ): array {
        $targetSite = SiteResolver::resolve($toSite);

        // Canonical entries only, unchanged: the copy lands as a draft OF the
        // canonical on the target site, and a draft of a draft is not a thing
        // Craft holds. Both ends resolve through the shared helpers now. The
        // target was a second, hand-rolled lookup that agreed with the first
        // only by coincidence, and its miss says "does not exist on site",
        // which diagnoses nothing but a section that is not enabled there.
        $source = $this->canonical($id, SiteResolver::resolve($fromSite), 'copy_entry_to_site');
        $targetEntry = Lookup::canonical($id, $targetSite)
            ?? throw new ToolCallException("Entry {$id} does not exist on site '{$toSite}'; the section may not be enabled for it");
        Authorization::assertCanSave($targetEntry);

        $payload = $this->reader->read($source, $fromSite);
        $fields = $this->perSiteFields($targetEntry, $payload['fields']);

        if ($fields === []) {
            return Response::success([
                'copiedFields' => [],
                // Scoped to the fields on purpose. This sentence used to end
                // "so '{$toSite}' already reads the same as '{$fromSite}'",
                // which is a claim about the whole entry that the tool has no
                // basis for: title and slug are per-site and deliberately left
                // alone, so two sites whose FIELDS all match can still read
                // "Audit Probe Alpha" and "Audit Probe Alpha Updated".
                'message' => "Nothing to copy: entry {$id} has no field that holds a separate value per site, so every field already reads the same on '{$toSite}' as on '{$fromSite}'. Title and slug are per-site and this tool never copies them, so those may still differ. describe_entry_schema reports each field's translation method.",
            ]);
        }

        $result = $this->writer->update($targetEntry, [], $fields, WriteMode::Draft, $toSite);

        return $result->isFailure()
            ? Response::failure($result->toArray())
            : Response::success([...$result->toArray(), 'copiedFields' => array_keys($fields)]);
    }

    /**
     * One review-queue row: identifiers for the tools, a deep link for the
     * human, and the draft note for context.
     *
     * @return array<string, mixed>
     */
    private function draftSummary(Entry $draft): array {
        $behavior = $draft->getBehavior('draft');
        $creator = $behavior instanceof DraftBehavior ? $behavior->getCreator() : null;
        $notes = $behavior instanceof DraftBehavior ? $behavior->draftNotes : null;

        return [
            'draftElementId' => (int) $draft->id,
            'canonicalId' => (int) $draft->getCanonicalId(),
            'isNewEntry' => $draft->getIsUnpublishedDraft(),
            'title' => (string) $draft->title,
            'section' => $draft->getSection()?->handle,
            'type' => $draft->getType()->handle,
            'site' => $draft->getSite()->handle,
            'creator' => $creator?->username,
            'notes' => $notes,
            'dateUpdated' => $draft->dateUpdated?->format('Y-m-d H:i:s'),
            'cpEditUrl' => $draft->getCpEditUrl(),
        ];
    }

    /**
     * One history row: identifiers, the human trail (creator, notes), and the
     * timestamp the revision was saved.
     *
     * @return array<string, mixed>
     */
    private function revisionSummary(Entry $revision): array {
        $behavior = $revision->getBehavior('revision');
        $creator = $behavior instanceof RevisionBehavior ? $behavior->getCreator() : null;
        $notes = $behavior instanceof RevisionBehavior ? $behavior->revisionNotes : null;
        $num = $behavior instanceof RevisionBehavior ? $behavior->revisionNum : null;

        return [
            'revisionElementId' => (int) $revision->id,
            'canonicalId' => (int) $revision->getCanonicalId(),
            'revisionNum' => $num,
            'title' => (string) $revision->title,
            'creator' => $creator?->username,
            'notes' => $notes,
            'dateCreated' => $revision->dateCreated?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The canonical entry an id names, for the tools that work on canonical
     * entries only, or a refusal that says what the id IS and which id to call
     * with instead.
     *
     * WHY the lookup admits every state and refuses afterwards, rather than
     * resolving with a query that already excludes drafts and revisions:
     * excluding them turns "494 is a draft of 132" into "Entry 494 not found"
     * for an element get_entry reads in full in the same session, and
     * that sends an agent hunting for a missing id instead of at the canonical
     * one. The refusal can only name that id while the derivative is still
     * findable. It is EntryResolver::writable's shape for the narrower set of
     * states these three tools accept; the states themselves differ per tool,
     * which is why this is not the same guard.
     *
     * @param string $tool the tool to name in the refusal, which is the call
     *                     the agent should make next with the canonical id
     */
    private function canonical(int $id, ?Site $site, string $tool): Entry {
        $entry = Lookup::inAnyState($id, $site) ?? throw EntryResolver::missing($id);

        if (!$entry->getIsDraft() && !$entry->getIsRevision()) {
            return $entry;
        }

        throw $this->notCanonical($entry, $tool);
    }

    /**
     * What a draft or revision id is, in the words the caller needs to act on
     * it. Kept apart from the lookup so the wording is testable without an
     * install to look anything up in.
     */
    private function notCanonical(Entry $entry, string $tool): ToolCallException {
        $id = (int) $entry->id;

        // An unpublished draft is its own canonical, so there is no other id to
        // send the caller to, and pointing back at this one would be a loop.
        if ($entry->getIsUnpublishedDraft()) {
            return new ToolCallException(
                "Entry {$id} is an unpublished draft and has never been a live entry, so there is no canonical entry"
                . " for {$tool} to work on. publish_entry makes it one, and {$tool} then takes this same id.",
            );
        }

        $state = $entry->getIsRevision() ? 'revision' : 'draft';
        $canonicalId = (int) $entry->getCanonicalId();

        return new ToolCallException(
            "Entry {$id} is a {$state} of entry {$canonicalId}; {$tool} works on the canonical entry."
            . " Call {$tool} with id {$canonicalId}.",
        );
    }

    /**
     * Of the source's field values, the ones the target can actually hold
     * separately.
     *
     * A field Craft does not treat as translatable holds ONE value shared by
     * every site, so writing the source's copy of it onto the target states
     * what was already true. It is not free: it costs a full re-save of the
     * target's field value, which is how an untranslated Matrix field's blocks
     * got dragged through Craft's nested-draft machinery on a copy that had
     * nothing to copy. Filtering here is what makes the tool's name true.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function perSiteFields(Entry $target, array $fields): array {
        $layout = LayoutFields::of($target->getFieldLayout());

        return array_filter(
            $fields,
            static fn (string $handle): bool => ($layout[$handle] ?? null)?->getIsTranslatable($target) === true,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The entry the copy sits under, or null for top level or a section with
     * no structure.
     *
     * Craft places a duplicate immediately after its source, which in a
     * structure section means under the same parent, and it does so for an
     * unpublished draft too because such a draft is its own canonical. Nothing
     * in the response said so, though, which is how a copy that DID keep its
     * place reads as one that quietly lost it.
     */
    private function structureParent(ElementInterface $entry): ?int {
        // Read back rather than ask the clone: Structures::moveAfter() writes
        // the placement straight into structureelements, so the element Craft
        // hands back still carries no lft/rgt and answers "no parent" for a
        // copy that has one. Reporting that would say the thing this key
        // exists to disprove.
        $placed = Lookup::withDrafts((int) $entry->id, $entry->getSite());

        if ($placed === null || $placed->structureId === null) {
            return null;
        }

        return $placed->getParent()?->id;
    }

    /**
     * Applies a draft onto its canonical entry: the moment the canonical
     * craft://entries/{section}/{slug} content actually changes for the
     * default draft-first flow, so this is where the resource push belongs.
     */
    private function applyDraft(Entry $draft, ?string $site, ?RequestContext $context): array {
        // A nested block's draft must carry the canonical's current position
        // into the apply, or Craft appends the block to the end of its field:
        // applyDraft clones with draftId nulled, which skips saveOwnership()'s
        // sortOrder recovery and falls through to max+1. Presetting the value
        // writes the right position inside the apply transaction.
        $sortOrder = NestedPosition::capture($draft);
        if ($sortOrder !== null) {
            $draft->setSortOrder($sortOrder);
        }

        $applied = Craft::$app->getDrafts()->applyDraft($draft);
        ResourceChangeNotifier::notifyEntry($context, (int) $applied->id);

        return Response::success([
            'entry' => $this->reader->read($applied, $site),
            'affectedSites' => Reach::of($applied),
        ]);
    }

    private function publishCanonical(Entry $entry, ?string $site, ?RequestContext $context): array {
        $drafts = $this->pendingDrafts($entry, $site);

        if (count($drafts) > 1) {
            $ids = implode(', ', array_map(static fn (Entry $draft): int => (int) $draft->id, $drafts));

            throw new ToolCallException(
                "Entry {$entry->id} has multiple pending drafts; publish one by its draft element id: {$ids}",
            );
        }

        if ($drafts !== []) {
            // The draft is the element being applied, so the permission check
            // must run against it (peer-draft rules), not only the canonical.
            Authorization::assertCanPublish($drafts[0]);

            return $this->applyDraft($drafts[0], $site, $context);
        }

        if (!$entry->enabled) {
            $this->enable($entry);
            ResourceChangeNotifier::notifyEntry($context, (int) $entry->id);

            return Response::success([
                'entry' => $this->reader->read($entry, $site),
                'affectedSites' => Reach::of($entry),
            ]);
        }

        throw new ToolCallException("Entry {$entry->id} has no pending draft and is already enabled; nothing to publish");
    }

    /**
     * Newest-first non-provisional pending drafts of a canonical entry.
     *
     * @return Entry[]
     */
    private function pendingDrafts(Entry $entry, ?string $site): array {
        $query = Entry::find()
            ->draftOf($entry->id)
            ->drafts()
            ->provisionalDrafts(false)
            ->status(null)
            ->orderBy(['dateUpdated' => SORT_DESC]);
        if ($site !== null) {
            $query->site($site);
        }

        return $query->all();
    }

    private function enable(Entry $entry): void {
        $entry->enabled = true;
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new ToolCallException('Failed to enable entry: ' . json_encode($entry->getErrors()));
        }
    }
}
