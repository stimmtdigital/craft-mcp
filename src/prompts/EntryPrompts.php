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
use stimmt\craft\Mcp\attributes\McpPromptMeta;
use stimmt\craft\Mcp\completions\EntryTypeHandleProvider;
use stimmt\craft\Mcp\completions\SectionHandleProvider;
use stimmt\craft\Mcp\enums\PromptCategory;
use stimmt\craft\Mcp\services\SchemaHelper;

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
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section|null $sectionObj */
        $sectionObj = $entriesService->getSectionByHandle($section);

        if ($sectionObj === null) {
            return $this->errorResponse("The section '{$section}' was not found.");
        }

        $entryTypes = $this->filterEntryTypes($sectionObj, $entryType);
        if ($entryTypes === null) {
            return $this->errorResponse("The entry type '{$entryType}' was not found in section '{$section}'.");
        }

        $guideJson = $this->buildCreateGuideJson($sectionObj, $entryTypes);

        return $this->promptResponse(<<<PROMPT
I want to create entries in Craft CMS. Here's the section structure:

```json
{$guideJson}
```

Please provide:
1. An explanation of how to create entries in this section using the create_entry tool
2. The required and optional fields for each entry type
3. Example JSON payload(s) for the 'fields' parameter
4. Any validation rules or constraints to be aware of
5. Common pitfalls to avoid when creating entries
PROMPT);
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
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section|null $sectionObj */
        $sectionObj = $entriesService->getSectionByHandle($section);

        if ($sectionObj === null) {
            return $this->errorResponse("The section '{$section}' was not found.");
        }

        $queryInfo = $this->buildQueryGuideJson($sectionObj);
        $entryCount = $this->getSectionEntryCount($section);

        return $this->promptResponse(<<<PROMPT
I need to query entries from this Craft CMS section:

```json
{$queryInfo}
```

Please provide guidance on:
1. How to use the list_entries tool effectively for this section
2. Available filter parameters based on the field types
3. Pagination strategies for the {$entryCount} entries
4. Performance optimization tips
5. Example queries for common use cases
PROMPT);
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
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section|null $sectionObj */
        $sectionObj = $entriesService->getSectionByHandle($section);

        if ($sectionObj === null) {
            return $this->errorResponse("The section '{$section}' was not found.");
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
1. How to safely iterate through all entries using list_entries with pagination
2. How to batch update entries using update_entry
3. Error handling and rollback strategies
4. Performance considerations for bulk operations
5. Best practices to avoid data loss or corruption

What kind of bulk operation would you like to perform?
PROMPT);
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
     * Create an error response.
     *
     * @return array{array{role: string, content: string}}
     */
    private function errorResponse(string $message): array {
        return [[
            'role' => 'user',
            'content' => "{$message} Please check the handle and try again.",
        ]];
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
