<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\behaviors\DraftBehavior;
use craft\behaviors\RevisionBehavior;
use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\NestedPosition;
use stimmt\craft\Mcp\support\ResourceChangeNotifier;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
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
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($site);

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
        description: 'List an entry\'s saved revisions, newest first: who saved each one, when, and with what notes. Answers "when did this change and by whom". Read a revision\'s full content with get_entry using its revisionElementId; the canonical entry id always holds the current content.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listRevisions(
        #[Schema(description: 'Canonical entry id whose history to list.')]
        int $id,
        #[Schema(description: 'Site handle whose revisions to list; list_sites reports the handles.')]
        ?string $site = null,
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($site);

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
    public function publishEntry(
        #[Schema(description: 'The draft element id to apply, or a canonical entry id that has exactly one pending draft (or none and is merely disabled).')]
        int $id,
        #[Schema(description: 'Site handle to publish on; list_sites reports the handles.')]
        ?string $site = null,
        ?RequestContext $context = null,
    ): array {
        $entry = $this->find($id, $site, withDrafts: true);
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
    public function deleteEntry(
        #[Schema(description: 'Element id to trash: an entry, a draft (draftElementId, which discards the draft and leaves the canonical entry untouched), or a single Matrix block by its own entry id.')]
        int $id,
        #[Schema(description: 'Site handle; list_sites reports the handles.')]
        ?string $site = null,
        ?RequestContext $context = null,
    ): array {
        $entry = $this->find($id, $site, withDrafts: true);
        Authorization::assertCanDelete($entry);

        if (!Craft::$app->getElements()->deleteElement($entry)) {
            throw new ToolCallException('Failed to delete entry');
        }

        return Response::success(['deleted' => $id, 'restorable' => true]);
    }

    #[McpTool(
        name: 'duplicate_entry',
        title: 'Duplicate an entry',
        description: 'Duplicate an entry as an unpublished draft. Optional title/slug overrides and a payload-format fields JSON for "like X but change these".',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
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
        $entry = $this->find($id, $site);
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
            'warnings' => $result === null ? [] : $result->toArray()['warnings'],
        ]);
    }

    #[McpTool(
        name: 'copy_entry_to_site',
        title: 'Copy an entry to another site',
        description: 'Copy an entry\'s field values from one site to another as a draft on the target site. Copies values; does not machine-translate.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function copyEntryToSite(
        #[Schema(description: 'Entry id, which is the same id on every site the entry exists on.')]
        int $id,
        #[Schema(description: 'Site handle to read the field values from.')]
        string $fromSite,
        #[Schema(description: 'Site handle to write the draft on. The entry must already exist there, which means its section is enabled for that site.')]
        string $toSite,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($fromSite);
        SiteResolver::resolve($toSite);

        $source = $this->find($id, $fromSite);
        $targetEntry = Entry::find()->id($id)->site($toSite)->status(null)->one()
            ?? throw new ToolCallException("Entry {$id} does not exist on site '{$toSite}'; the section may not be enabled for it");
        Authorization::assertCanSave($targetEntry);

        $payload = $this->reader->read($source, $fromSite);
        $result = $this->writer->update($targetEntry, [], $payload['fields'], WriteMode::Draft, $toSite);

        return $result->isFailure()
            ? Response::failure($result->toArray())
            : Response::success($result->toArray());
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

        return Response::success(['entry' => $this->reader->read($applied, $site)]);
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

            return Response::success(['entry' => $this->reader->read($entry, $site)]);
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

    private function find(int $id, ?string $site, bool $withDrafts = false): Entry {
        SiteResolver::resolve($site);

        $query = Entry::find()->id($id)->status(null);
        if ($site !== null) {
            $query->site($site);
        }
        if ($withDrafts) {
            $query->drafts(null);
        }

        return $query->one() ?? throw new ToolCallException("Entry with ID {$id} not found");
    }
}
