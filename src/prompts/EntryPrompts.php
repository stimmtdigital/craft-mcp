<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\prompts;

use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use stimmt\craft\Mcp\attributes\McpPromptMeta;
use stimmt\craft\Mcp\completions\EntryTypeHandleProvider;
use stimmt\craft\Mcp\completions\SectionHandleProvider;
use stimmt\craft\Mcp\enums\PromptCategory;
use stimmt\craft\Mcp\services\SchemaHelper;
use stimmt\craft\Mcp\support\HandleResolver;

/**
 * MCP prompts for working with Craft CMS entries.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EntryPrompts {
    /**
     * Generate a prompt for creating entries in a section.
     *
     * @param string $section Handle of the section the new entries belong in.
     * @param string|null $entryType Handle of one entry type to focus the guide on; omit to cover every type the section allows.
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'create_entry_guide',
        description: 'Get guidance on creating entries for a specific section, including required fields and validation rules.',
    )]
    #[McpPromptMeta(category: PromptCategory::CONTENT)]
    public function createEntryGuide(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $section,
        #[CompletionProvider(provider: EntryTypeHandleProvider::class)]
        ?string $entryType = null,
    ): array {
        $sectionObj = HandleResolver::section($section);

        $entryTypes = $entryType === null
            ? array_values($sectionObj->getEntryTypes())
            : [HandleResolver::entryType($entryType, $sectionObj)];

        $guideJson = $this->buildCreateGuideJson($sectionObj, $entryTypes);

        return $this->promptResponse(<<<PROMPT
I want to create entries in Craft CMS. Here's the section structure:

```json
{$guideJson}
```

Work with the payload format, not guesses:
1. First call describe_entry_schema for this section (pass example with an existing entry id or slug to get a golden fixture); every field's 'input' shape is the exact payload it accepts, and its meta attributes are the writable ones only, with Craft's internal columns filtered out
2. Relations use natural keys ({"section": "...", "slug": "..."}, {"volume": "...", "filename": "..."}), never numeric ids; keys resolve against unpublished drafts too, so an entry created moments ago can already be related to
3. Matrix blocks are keyed objects with the entry-type handle as 'type'; reads carry a 1-based 'position' per block and writes honour it, so block order survives a read-modify-write round trip
4. Once the entry exists, add a further block with create_nested_entry and reorder one with move_nested_entry; resending the whole Matrix field replaces its entire value and deletes any block left out of the payload
5. postDate and expiryDate are named arguments on create_entry, not entries in the fields payload
6. What get_entry returns is exactly what create_entry accepts, so an existing entry is a valid template
7. Writes save as drafts by default: review via the returned cpEditUrl, then publish_entry makes them live
8. Check the 'warnings' list on every write response; unresolvable keys become warnings, and validation failures return per-field errors

The full contract lives in the craft://guides/content-writing resource.

Please walk me through creating an entry in this section following that flow, including a concrete fields payload built from the schema's input shapes.
PROMPT);
    }

    /**
     * Generate a prompt for querying entries effectively.
     *
     * @param string $section Handle of the section to query entries from.
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'query_entries_guide',
        description: 'Get guidance on querying entries in a section with optimal performance.',
    )]
    #[McpPromptMeta(category: PromptCategory::CONTENT)]
    public function queryEntriesGuide(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $section,
    ): array {
        $queryInfo = $this->buildQueryGuideJson(HandleResolver::section($section));
        $entryCount = $this->getSectionEntryCount($section);

        return $this->promptResponse(<<<PROMPT
I need to query entries from this Craft CMS section:

```json
{$queryInfo}
```

Please provide guidance on:
1. How to use the list_entries tool effectively for this section, including full-text `search`, the `site` parameter, field-value `filters` (with :empty:/:notempty: and natural keys), `relatedTo`, date ranges, and the `fields` projection for slim rows
2. Which entries each tool sees by default: list_entries returns live entries only unless `status` is passed (`status: "any"` for every state), while count_entries counts every status unless narrowed
3. When count_entries answers the question without listing anything (totals, per-value breakdowns, per-month trends via groupBy)
4. Pagination strategies for the {$entryCount} entries
5. Performance optimization tips
6. Example queries for common use cases
PROMPT);
    }

    /**
     * Generate a prompt for bulk entry operations.
     *
     * @param string $section Handle of the section whose entries the batch will touch.
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'bulk_entry_operations',
        description: 'Get guidance on performing bulk operations on entries in a section.',
    )]
    #[McpPromptMeta(category: PromptCategory::WORKFLOW)]
    public function bulkEntryOperations(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $section,
    ): array {
        $sectionObj = HandleResolver::section($section);
        $entryCount = $this->getSectionEntryCount($section);
        $entryTypes = $this->getEntryTypeHandles($sectionObj);
        $sectionName = $sectionObj->name ?? $section;

        return $this->promptResponse(<<<PROMPT
I need to perform bulk operations on entries in the "{$sectionName}" section ({$section}).

Current state:
- Total entries: {$entryCount}
- Entry types: {$entryTypes}

Please help me understand:
1. How to scope the batch first: count_entries with the same filters shows exactly how many entries a bulk operation will touch, then list_entries with those filters (and a `fields` projection) iterates them
2. How to batch update entries using update_entry: each write lands as a draft on top of the live entry, so nothing changes for visitors until publish_entry runs per entry
3. How to keep a long batch safe from concurrent edits: pass expectedDateUpdated (the dateUpdated string get_entry returned for that same id) so a write fails naming both timestamps instead of overwriting a change made since the read
4. How to touch one Matrix block per entry without rewriting the field: update_entry or delete_entry on the block's own id, create_nested_entry to add one, move_nested_entry to reorder one. Sending the owner's whole Matrix field deletes every block left out of the payload
5. How drafts double as the safety net: a wrong batch is discarded drafts, not corrupted live content; review spot checks via each response's cpEditUrl before publishing
6. When duplicate_entry ("like X but change these") or copy_entry_to_site fits the job better than editing in place
7. Reading the 'warnings' list on every write response, since unresolvable natural keys warn instead of failing the save

What kind of bulk operation would you like to perform?
PROMPT);
    }

    /**
     * Generate a prompt for reviewing the pending draft queue.
     *
     * @param string|null $section Handle of a section to narrow the review queue to; omit to review drafts from every section.
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'review_pending_drafts',
        description: 'Walk through the pending entry drafts awaiting review: inspect, publish, or reject each one.',
    )]
    #[McpPromptMeta(category: PromptCategory::WORKFLOW)]
    public function reviewPendingDrafts(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        ?string $section = null,
    ): array {
        $query = Entry::find()->drafts()->provisionalDrafts(false)->status(null);
        if ($section !== null) {
            $query->section($section);
        }

        $pending = (int) $query->count();
        $scopeLine = $section !== null ? "the \"{$section}\" section" : 'all sections';

        return $this->promptResponse(<<<PROMPT
I want to review the pending entry drafts in {$scopeLine}. There are currently {$pending} non-provisional drafts awaiting review.

Please walk me through the review queue:
1. Call list_drafts (filter by section, site, or creator as needed) to get the queue, newest first; each row has a draftElementId, a canonicalId, an isNewEntry flag, the creator, the draft notes, and a cpEditUrl
2. For each draft I pick: fetch its content with get_entry using the draftElementId, and summarize what it changes; when isNewEntry is false, compare against get_entry on the canonicalId to show the diff
3. Blocks added or reordered with create_nested_entry or move_nested_entry stage on a draft of their owner entry, so they surface here as an owner draft rather than as a row of their own
4. To approve: publish_entry with the draftElementId; to reject: delete_entry with the draftElementId (the canonical entry is untouched either way)
5. Share the cpEditUrl whenever I want to look at a draft in the control panel myself

Start by showing me the queue.
PROMPT);
    }

    /**
     * Build JSON for the create entry guide.
     *
     * @param list<EntryType> $entryTypes
     */
    private function buildCreateGuideJson(Section $section, array $entryTypes): string {
        $typeGuides = array_map(
            $this->buildEntryTypeGuide(...),
            $entryTypes,
        );

        $json = json_encode([
            'section' => [
                'handle' => $section->handle ?? '',
                'name' => $section->name ?? '',
                'type' => $section->type ?? 'channel',
            ],
            'entryTypes' => $typeGuides,
        ], JSON_PRETTY_PRINT);

        return $json !== false ? $json : '{}';
    }

    /**
     * Build guide data for an entry type.
     *
     * @return array{handle: string, name: string, hasTitleField: bool, titleFormat: string|null, fields: list<array{handle: string, name: string, type: string, required: bool, instructions: string|null}>}
     */
    private function buildEntryTypeGuide(EntryType $type): array {
        return [
            'handle' => $type->handle ?? '',
            'name' => $type->name ?? '',
            'hasTitleField' => $type->hasTitleField,
            'titleFormat' => $type->titleFormat !== '' ? $type->titleFormat : null,
            'fields' => SchemaHelper::getEntryTypeFieldsExtended($type),
        ];
    }

    /**
     * Build JSON for the query guide.
     */
    private function buildQueryGuideJson(Section $section): string {
        $allFields = SchemaHelper::collectSectionFields($section);
        $handle = $section->handle ?? '';
        $entryCount = $this->getSectionEntryCount($handle);

        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        $json = json_encode([
            'section' => [
                'handle' => $handle,
                'name' => $section->name ?? '',
                'entryCount' => $entryCount,
            ],
            'availableFields' => array_values($allFields),
            'entryTypeCount' => count($entryTypes),
        ], JSON_PRETTY_PRINT);

        return $json !== false ? $json : '{}';
    }

    /**
     * Get the entry count for a section.
     */
    private function getSectionEntryCount(string $section): int {
        return (int) Entry::find()
            ->section($section)
            ->status(null)
            ->count();
    }

    /**
     * Get entry type handles as a comma-separated string.
     */
    private function getEntryTypeHandles(Section $section): string {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        $handles = array_map(
            fn (EntryType $type): string => $type->handle ?? '',
            $entryTypes,
        );

        return implode(', ', $handles);
    }

    /**
     * Create a prompt response.
     *
     * @return array{array{role: string, content: string}}
     */
    private function promptResponse(string $content): array {
        return [[
            'role' => 'user',
            'content' => $content,
        ]];
    }
}
