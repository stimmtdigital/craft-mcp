<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\resources;

use Craft;
use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Entries;
use craft\services\Fields;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use stimmt\craft\Mcp\attributes\McpResourceMeta;
use stimmt\craft\Mcp\completions\FieldHandleProvider;
use stimmt\craft\Mcp\completions\SectionHandleProvider;
use stimmt\craft\Mcp\enums\ResourceCategory;
use stimmt\craft\Mcp\services\SchemaHelper;

/**
 * MCP resources for Craft CMS schema information.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SchemaResources {
    /**
     * Get all sections in the Craft CMS installation.
     *
     * @return array{sections: list<array{handle: string, name: string, type: string, entryTypeCount: int}>}
     */
    #[McpResource(
        uri: 'craft://schema/sections',
        name: 'all-sections',
        description: 'List of all sections in the Craft CMS installation with their basic metadata.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::SCHEMA)]
    public function allSections(): array {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section[] $sections */
        $sections = $entriesService->getAllSections();

        return [
            'sections' => array_values(array_map($this->buildSectionSummary(...), $sections)),
        ];
    }

    /**
     * Get all fields in the Craft CMS installation.
     *
     * @return array{fields: list<array{handle: string, name: string, type: string, group: string|null, searchable: bool}>}
     */
    #[McpResource(
        uri: 'craft://schema/fields',
        name: 'all-fields',
        description: 'List of all custom fields in the Craft CMS installation with their types and groups.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::SCHEMA)]
    public function allFields(): array {
        /** @var Fields $fieldsService */
        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface[] $fields */
        $fields = $fieldsService->getAllFields();

        return [
            'fields' => array_values(array_map($this->buildFieldSummary(...), $fields)),
        ];
    }

    /**
     * Get detailed schema for a specific section.
     *
     * @return array{section: array{handle: string, name: string, type: string, hasUrls: bool, enableVersioning: bool}, entryTypes: list<array{handle: string, name: string, hasTitleField: bool, fields: list<array{handle: string, name: string, type: string, required: bool}>}>}|array{error: string}
     */
    #[McpResourceTemplate(
        uriTemplate: 'craft://schema/sections/{handle}',
        name: 'section-schema',
        description: 'Detailed schema information for a specific section including entry types and fields.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::SCHEMA)]
    public function sectionSchema(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $handle,
    ): array {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section|null $section */
        $section = $entriesService->getSectionByHandle($handle);

        if ($section === null) {
            return ['error' => "Section '{$handle}' not found"];
        }

        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        $entryTypeSchemas = array_values(array_map(
            SchemaHelper::buildEntryTypeSchema(...),
            $entryTypes,
        ));

        return [
            'section' => SchemaHelper::buildSectionSchema($section),
            'entryTypes' => $entryTypeSchemas,
        ];
    }

    /**
     * Get detailed information about a specific field.
     *
     * @return array{field: array{handle: string, name: string, type: string, instructions: string|null, searchable: bool, translationMethod: string}, usedIn: list<array{section: string, entryType: string}>}|array{error: string}
     */
    #[McpResourceTemplate(
        uriTemplate: 'craft://schema/fields/{handle}',
        name: 'field-details',
        description: 'Detailed information about a specific field including where it is used.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::SCHEMA)]
    public function fieldDetails(
        #[CompletionProvider(provider: FieldHandleProvider::class)]
        string $handle,
    ): array {
        /** @var Fields $fieldsService */
        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface|null $field */
        $field = $fieldsService->getFieldByHandle($handle);

        if ($field === null) {
            return ['error' => "Field '{$handle}' not found"];
        }

        return [
            'field' => [
                'handle' => $field->handle ?? '',
                'name' => $field->name ?? '',
                'type' => SchemaHelper::getFieldTypeName($field),
                'instructions' => $field->instructions !== '' ? $field->instructions : null,
                'searchable' => $field->searchable,
                'translationMethod' => $field->translationMethod ?? 'none',
            ],
            'usedIn' => SchemaHelper::findFieldUsage($handle),
        ];
    }

    /**
     * Build a section summary.
     *
     * @return array{handle: string, name: string, type: string, entryTypeCount: int}
     */
    private function buildSectionSummary(Section $section): array {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        return [
            'handle' => $section->handle ?? '',
            'name' => $section->name ?? '',
            'type' => $section->type ?? 'channel',
            'entryTypeCount' => count($entryTypes),
        ];
    }

    /**
     * Build a field summary.
     *
     * @return array{handle: string, name: string, type: string, group: string|null, searchable: bool}
     */
    private function buildFieldSummary(FieldInterface $field): array {
        $groupName = $this->getFieldGroupName($field);

        return [
            'handle' => $field->handle ?? '',
            'name' => $field->name ?? '',
            'type' => SchemaHelper::getFieldTypeName($field),
            'group' => $groupName,
            'searchable' => $field->searchable,
        ];
    }

    /**
     * Get the field group name if available.
     */
    private function getFieldGroupName(FieldInterface $field): ?string {
        if (!method_exists($field, 'getGroup')) {
            return null;
        }

        $group = $field->getGroup();
        if ($group === null || !is_object($group)) {
            return null;
        }

        $name = $group->name ?? null;

        return is_string($name) ? $name : null;
    }
}
