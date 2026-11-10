<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\models\Site;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\Lookup;
use stimmt\craft\Mcp\elements\Reach;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\EntryResolver;
use stimmt\craft\Mcp\support\NestedPosition;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;

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
        name: 'stimmt_drafts_publish',
        title: 'Publish an entry',
        description: 'Publish an entry: applies a draft (by draft element id, or a canonical id with exactly one pending draft) to its canonical entry, or enables a disabled live entry.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresEdition(Edition::Pro)]
    public function publishEntry(
        #[Schema(description: 'The draft element id to apply, or a canonical entry id that has exactly one pending draft (or none and is merely disabled).')]
        int $id,
        #[Schema(description: 'Site handle to locate the entry by and to report the result in. Publishing is not per-site: the draft is applied to every site it exists on, so this only selects which site\'s values come back.')]
        ?string $site = null,
        ?RequestContext $context = null,
    ): array|CallToolResult {
        $entry = EntryResolver::writable($id, SiteResolver::resolve($site), 'stimmt_drafts_publish');
        Authorization::assertCanPublish($entry);

        if ($entry->getIsDraft()) {
            return $this->applyDraft($entry, $site);
        }

        return $this->publishCanonical($entry, $site);
    }

    #[McpTool(
        name: 'stimmt_entries_copy_to_site',
        title: 'Copy an entry to another site',
        description: 'Copy the field values an entry keeps SEPARATELY PER SITE from one site to another, as a draft on the target site. Copies values; does not machine-translate. Title and slug are left alone so a translated title survives, and so is any field Craft does not treat as translatable, because that field already holds one shared value on every site. The response lists the handles actually copied; an empty list means this entry type keeps no field per site, so its fields already match on both sites, though their titles and slugs still may not.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[RequiresEdition(Edition::Pro)]
    public function copyEntryToSite(
        #[Schema(description: 'Entry id, which is the same id on every site the entry exists on.')]
        int $id,
        #[Schema(description: 'Site handle to read the field values from.')]
        string $fromSite,
        #[Schema(description: 'Site handle to write the draft on. The entry must already exist there, which means its section is enabled for that site.')]
        string $toSite,
        ?RequestContext $context = null,
    ): array|CallToolResult {
        $targetSite = SiteResolver::resolve($toSite);
        // Checked after both handles resolve, so a misspelt one is reported as
        // unknown rather than as a copy onto itself.
        if ($targetSite?->id === SiteResolver::resolve($fromSite)?->id) {
            throw new ToolCallException("fromSite and toSite are both '{$toSite}'; copying a site onto itself changes nothing. Pass the site to copy to as toSite.");
        }

        // Canonical entries only, unchanged: the copy lands as a draft OF the
        // canonical on the target site, and a draft of a draft is not a thing
        // Craft holds. Both ends resolve through the shared helpers now. The
        // target was a second, hand-rolled lookup that agreed with the first
        // only by coincidence, and its miss says "does not exist on site",
        // which diagnoses nothing but a section that is not enabled there.
        $source = $this->canonical($id, SiteResolver::resolve($fromSite), 'stimmt_entries_copy_to_site');
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
                'message' => "Nothing to copy: entry {$id} has no field that holds a separate value per site, so every field already reads the same on '{$toSite}' as on '{$fromSite}'. Title and slug are per-site and this tool never copies them, so those may still differ.",
            ]);
        }

        $result = $this->writer->update($targetEntry, [], $fields, WriteMode::Draft, $toSite);

        return $result->isFailure()
            ? Response::failure($result->toArray())
            : Response::success([...$result->toArray(), 'copiedFields' => array_keys($fields)]);
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
                . " for {$tool} to work on. stimmt_drafts_publish makes it one, and {$tool} then takes this same id.",
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
     * Applies a draft onto its canonical entry: the moment the canonical
     * craft://entries/{section}/{slug} content actually changes for the
     * default draft-first flow, so this is where the resource push belongs.
     */
    private function applyDraft(Entry $draft, ?string $site): array|CallToolResult {
        // A nested block's draft must carry the canonical's current position
        // into the apply, or Craft appends the block to the end of its field:
        // applyDraft clones with draftId nulled, which skips saveOwnership()'s
        // sortOrder recovery and falls through to max+1. Presetting the value
        // writes the right position inside the apply transaction.
        $sortOrder = NestedPosition::capture($draft);
        if ($sortOrder !== null) {
            $draft->setSortOrder($sortOrder);
        }

        $errors = $this->liveErrors($draft);
        if ($errors !== []) {
            return Response::failure([
                'action' => 'publish',
                'draftElementId' => (int) $draft->id,
                'message' => 'Not published: the draft does not pass validation for live content. Fix the fields listed in errors with elements_update, then publish again.',
                'errors' => $errors,
            ]);
        }

        $applied = Craft::$app->getDrafts()->applyDraft($draft);

        return Response::success([
            'entry' => $this->reader->read($applied, $site),
            'affectedSites' => Reach::of($applied),
        ]);
    }

    private function publishCanonical(Entry $entry, ?string $site): array|CallToolResult {
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

            return $this->applyDraft($drafts[0], $site);
        }

        if (!$entry->enabled) {
            $errors = $this->enable($entry);
            if ($errors !== []) {
                return Response::failure([
                    'action' => 'publish',
                    'message' => 'Not published: the entry does not pass validation for live content. Fix the fields listed in errors with elements_update, then publish again.',
                    'errors' => $errors,
                ]);
            }


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

    /**
     * Turn a disabled entry on, validating it as live content: a disabled
     * entry was saved under the looser rules, so a required field can be
     * empty, and enabling it must not skip the check publishing a draft gets.
     *
     * @return array<string, string[]> the validation errors; empty when enabled
     */
    private function enable(Entry $entry): array {
        $entry->enabled = true;
        $entry->setScenario(Element::SCENARIO_LIVE);

        if (Craft::$app->getElements()->saveElement($entry)) {
            return [];
        }

        $entry->enabled = false;

        return $entry->getErrors();
    }

    /**
     * Validate a draft the way the control panel does before applying it
     * (ElementsController::actionApplyDraft()): under the live scenario when
     * it will be live, with required fields propagated for a new entry, and
     * across sites on a multi-site install. Drafts::applyDraft() validates
     * nothing itself, so without this a draft missing a required field went
     * live (#76). Saving the draft first is what the control panel does too;
     * it changes nothing about the draft but runs every validator.
     *
     * @return array<string, string[]> the validation errors; empty when the draft may be applied
     */
    private function liveErrors(Entry $draft): array {
        if ($draft->enabled && $draft->getEnabledForSite()) {
            $draft->setScenario(Element::SCENARIO_LIVE);
        }

        if ($draft->getIsUnpublishedDraft()) {
            $draft->propagateRequired = true;
        }

        $draft->applyingDraft = true;
        $valid = Craft::$app->getElements()->saveElement($draft, crossSiteValidate: Craft::$app->getIsMultiSite());
        $draft->applyingDraft = false;

        return $valid ? [] : $draft->getErrors();
    }
}
