<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\prompts;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Entries;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Exception\PromptGetException;
use stimmt\craft\Mcp\attributes\McpPromptMeta;
use stimmt\craft\Mcp\completions\EntryTypeHandleProvider;
use stimmt\craft\Mcp\completions\SectionHandleProvider;
use stimmt\craft\Mcp\enums\PromptCategory;
use stimmt\craft\Mcp\services\SchemaHelper;
use stimmt\craft\Mcp\support\SafePromptExecution;

/**
 * MCP prompts for working with Craft CMS entries.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EntryPrompts {
    /**
     * Generate a prompt for creating entries in a section.
     *
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
        return SafePromptExecution::run(function () use ($section, $entryType): array {
            /** @var Entries $entriesService */
            $entriesService = Craft::$app->getEntries();

            /** @var Section|null $sectionObj */
            $sectionObj = $entriesService->getSectionByHandle($section);

            if ($sectionObj === null) {
                throw new PromptGetException("The section '{$section}' was not found.");
            }

            $entryTypes = $this->filterEntryTypes($sectionObj, $entryType);
            if ($entryTypes === null) {
                throw new PromptGetException("The entry type '{$entryType}' was not found in section '{$section}'.");
            }

            $guideJson = $this->buildCreateGuideJson($sectionObj, $entryTypes);

            return $this->promptResponse(<<<PROMPT
I want to create entries in Craft CMS. Here's the section structure:

```json
{$guideJson}
```

Work with the payload format, not guesses:
1. First call describe_entry_schema for this section (pass example with an existing entry id or slug to get a golden fixture); every field's 'input' shape is the exact payload it accepts
2. Relations use natural keys ({"section": "...", "slug": "..."}, {"volume": "...", "filename": "..."}), never numeric ids; Matrix blocks are keyed objects with the entry-type handle as 'type'
3. What get_entry returns is exactly what create_entry accepts, so an existing entry is a valid template
4. Writes save as drafts by default: review via the returned cpEditUrl, then publish_entry makes them live
5. Check the 'warnings' list on every write response; unresolvable keys become warnings, and validation failures return per-field errors

Please walk me through creating an entry in this section following that flow, including a concrete fields payload built from the schema's input shapes.
PROMPT);
        });
    }

    /**
     * Generate a prompt for querying entries effectively.
     *
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
        return SafePromptExecution::run(function () use ($section): array {
            /** @var Entries $entriesService */
            $entriesService = Craft::$app->getEntries();

            /** @var Section|null $sectionObj */
            $sectionObj = $entriesService->getSectionByHandle($section);

            if ($sectionObj === null) {
                throw new PromptGetException("The section '{$section}' was not found.");
            }

            $queryInfo = $this->buildQueryGuideJson($sectionObj);
            $entryCount = $this->getSectionEntryCount($section);

            return $this->promptResponse(<<<PROMPT
I need to query entries from this Craft CMS section:

```json
{$queryInfo}
```

Please provide guidance on:
1. How to use the list_entries tool effectively for this section, including the full-text 'search' and multi-site 'site' parameters
2. Available filter parameters based on the field types
3. Pagination strategies for the {$entryCount} entries
4. Performance optimization tips
5. Example queries for common use cases
PROMPT);
        });
    }

    /**
     * Generate a prompt for bulk entry operations.
     *
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
        return SafePromptExecution::run(function () use ($section): array {
            /** @var Entries $entriesService */
            $entriesService = Craft::$app->getEntries();

            /** @var Section|null $sectionObj */
            $sectionObj = $entriesService->getSectionByHandle($section);

            if ($sectionObj === null) {
                throw new PromptGetException("The section '{$section}' was not found.");
            }

            $entryCount = $this->getSectionEntryCount($section);
            $entryTypes = $this->getEntryTypeHandles($sectionObj);
            $sectionName = $sectionObj->name ?? $section;

            return $this->promptResponse(<<<PROMPT
I need to perform bulk operations on entries in the "{$sectionName}" section ({$section}).

Current state:
- Total entries: {$entryCount}
- Entry types: {$entryTypes}

Please help me understand:
1. How to safely iterate through all entries using list_entries with pagination (and the search/site filters)
2. How to batch update entries using update_entry: each write lands as a draft on top of the live entry, so nothing changes for visitors until publish_entry runs per entry
3. How drafts double as the safety net: a wrong batch is discarded drafts, not corrupted live content; review spot checks via each response's cpEditUrl before publishing
4. When duplicate_entry ("like X but change these") or copy_entry_to_site fits the job better than editing in place
5. Reading the 'warnings' list on every write response, since unresolvable natural keys warn instead of failing the save

What kind of bulk operation would you like to perform?
PROMPT);
        });
    }

    /**
     * Filter entry types to a specific one if provided.
     *
     * @return list<EntryType>|null
     */
    private function filterEntryTypes(Section $section, ?string $entryTypeHandle): ?array {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        if ($entryTypeHandle === null) {
            return array_values($entryTypes);
        }

        $filtered = array_filter(
            $entryTypes,
            fn (EntryType $type): bool => $type->handle === $entryTypeHandle,
        );

        return $filtered === [] ? null : array_values($filtered);
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
