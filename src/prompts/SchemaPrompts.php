<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\prompts;

use Craft;
use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Entries;
use craft\services\Fields;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use stimmt\craft\Mcp\attributes\McpPromptMeta;
use stimmt\craft\Mcp\completions\FieldHandleProvider;
use stimmt\craft\Mcp\completions\SectionHandleProvider;
use stimmt\craft\Mcp\enums\PromptCategory;
use stimmt\craft\Mcp\services\SchemaHelper;

/**
 * MCP prompts for exploring Craft CMS schema.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SchemaPrompts {
    /**
     * Generate a prompt for exploring a section's schema and field structure.
     *
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'explore_section_schema',
        description: 'Get detailed schema information about a Craft CMS section including its entry types, fields, and relationships.',
    )]
    #[McpPromptMeta(category: PromptCategory::SCHEMA)]
    public function exploreSectionSchema(
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

        $schemaJson = $this->buildSectionSchemaJson($sectionObj);

        return $this->promptResponse(<<<PROMPT
Analyze the following Craft CMS section schema and provide insights:

```json
{$schemaJson}
```

Please describe:
1. The section's purpose based on its name and structure
2. The entry types available and what content they represent
3. The field configuration and data types
4. Any relationships or complex field types
5. Suggestions for querying or managing entries in this section
PROMPT);
    }

    /**
     * Generate a prompt for understanding field usage across the site.
     *
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'field_usage_analysis',
        description: 'Analyze how a specific field is used across sections and entry types in Craft CMS.',
    )]
    #[McpPromptMeta(category: PromptCategory::SCHEMA)]
    public function fieldUsageAnalysis(
        #[CompletionProvider(provider: FieldHandleProvider::class)]
        string $fieldHandle,
    ): array {
        /** @var Fields $fieldsService */
        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface|null $field */
        $field = $fieldsService->getFieldByHandle($fieldHandle);

        if ($field === null) {
            return $this->errorResponse("The field '{$fieldHandle}' was not found.");
        }

        $usageJson = $this->buildFieldUsageJson($field, $fieldHandle);

        return $this->promptResponse(<<<PROMPT
Analyze how this field is used in the Craft CMS installation:

```json
{$usageJson}
```

Please describe:
1. What this field is designed to store
2. Where it's being used across the content model
3. Whether the usage pattern seems consistent and appropriate
4. Any potential issues or suggestions for improvement
PROMPT);
    }

    /**
     * Generate a prompt for exploring the complete content model.
     *
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'explore_content_model',
        description: 'Get a comprehensive overview of the Craft CMS content model including all sections, entry types, and field relationships.',
    )]
    #[McpPromptMeta(category: PromptCategory::SCHEMA)]
    public function exploreContentModel(): array {
        $modelJson = $this->buildContentModelJson();

        return $this->promptResponse(<<<PROMPT
Analyze this Craft CMS content model structure:

```json
{$modelJson}
```

Please provide:
1. An overview of the content architecture
2. The main content types and their purposes
3. How different sections might relate to each other
4. Any observations about the field structure and complexity
5. Suggestions for content management workflows
PROMPT);
    }

    /**
     * Build JSON schema for a section.
     */
    private function buildSectionSchemaJson(Section $section): string {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        $typeInfo = array_map(
            SchemaHelper::buildEntryTypeSchema(...),
            $entryTypes,
        );

        $json = json_encode([
            'section' => [
                'handle' => $section->handle ?? '',
                'name' => $section->name ?? '',
                'type' => $section->type ?? 'channel',
            ],
            'entryTypes' => $typeInfo,
        ], JSON_PRETTY_PRINT);

        return $json !== false ? $json : '{}';
    }

    /**
     * Build JSON for field usage analysis.
     */
    private function buildFieldUsageJson(FieldInterface $field, string $fieldHandle): string {
        $usageInfo = SchemaHelper::findFieldUsage($fieldHandle);

        $json = json_encode([
            'field' => [
                'handle' => $field->handle ?? '',
                'name' => $field->name ?? '',
                'type' => SchemaHelper::getFieldTypeName($field),
                'instructions' => $field->instructions ?? '',
            ],
            'usedIn' => $usageInfo,
            'usageCount' => count($usageInfo),
        ], JSON_PRETTY_PRINT);

        return $json !== false ? $json : '{}';
    }

    /**
     * Build JSON for the complete content model.
     */
    private function buildContentModelJson(): string {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section[] $sections */
        $sections = $entriesService->getAllSections();

        $model = array_values(array_map(
            $this->buildSectionSummary(...),
            $sections,
        ));

        $json = json_encode($model, JSON_PRETTY_PRINT);

        return $json !== false ? $json : '[]';
    }

    /**
     * Build a summary of a section for the content model.
     *
     * @return array{handle: string, name: string, type: string, entryTypes: list<array{handle: string, fieldCount: int, fields: list<array{handle: string, type: string}>}>}
     */
    private function buildSectionSummary(Section $section): array {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        $entryTypeSummaries = array_values(array_map(
            $this->buildEntryTypeSummary(...),
            $entryTypes,
        ));

        return [
            'handle' => $section->handle ?? '',
            'name' => $section->name ?? '',
            'type' => $section->type ?? 'channel',
            'entryTypes' => $entryTypeSummaries,
        ];
    }

    /**
     * Build a summary of an entry type.
     *
     * @return array{handle: string, fieldCount: int, fields: list<array{handle: string, type: string}>}
     */
    private function buildEntryTypeSummary(EntryType $entryType): array {
        $fields = SchemaHelper::getEntryTypeFields($entryType);
        $simplifiedFields = array_values(array_map(
            fn (array $field): array => [
                'handle' => $field['handle'],
                'type' => $field['type'],
            ],
            $fields,
        ));

        return [
            'handle' => $entryType->handle ?? '',
            'fieldCount' => count($fields),
            'fields' => $simplifiedFields,
        ];
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
