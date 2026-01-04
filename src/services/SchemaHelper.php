<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Entries;
use ReflectionClass;

/**
 * Helper service for schema-related operations.
 *
 * Centralizes common schema traversal patterns to avoid code duplication
 * and deeply nested loops in prompts/resources.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SchemaHelper {
    /**
     * Find all locations where a field is used.
     *
     * @return list<array{section: string, entryType: string}>
     */
    public static function findFieldUsage(string $fieldHandle): array {
        return array_values(array_filter(
            array_map(
                fn (array $location): ?array => self::entryTypeHasField($location['entryType'], $fieldHandle)
                    ? ['section' => $location['section']->handle ?? '', 'entryType' => $location['entryType']->handle ?? '']
                    : null,
                self::getAllEntryTypes(),
            ),
            fn (?array $item): bool => $item !== null,
        ));
    }

    /**
     * Get all entry types with their parent sections.
     *
     * Uses array_merge with spread to flatten section->entryTypes into a single list.
     *
     * @return list<array{section: Section, entryType: EntryType}>
     */
    public static function getAllEntryTypes(): array {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section[] $sections */
        $sections = $entriesService->getAllSections();

        if ($sections === []) {
            return [];
        }

        return array_values(array_merge(...array_map(
            fn (Section $section): array => array_map(
                fn (EntryType $entryType): array => ['section' => $section, 'entryType' => $entryType],
                $section->getEntryTypes(),
            ),
            $sections,
        )));
    }

    /**
     * Check if an entry type has a specific field.
     */
    public static function entryTypeHasField(EntryType $entryType, string $fieldHandle): bool {
        $fields = $entryType->getFieldLayout()->getCustomFields();

        return array_any($fields, fn (FieldInterface $field): bool => $field->handle === $fieldHandle);
    }

    /**
     * Get fields for an entry type.
     *
     * @return list<array{handle: string, name: string, type: string, required: bool}>
     */
    public static function getEntryTypeFields(EntryType $entryType): array {
        return array_values(array_map(
            fn (FieldInterface $field): array => [
                'handle' => $field->handle ?? '',
                'name' => $field->name ?? '',
                'type' => self::getFieldTypeName($field),
                'required' => $field->required ?? false,
            ],
            $entryType->getFieldLayout()->getCustomFields(),
        ));
    }

    /**
     * Get extended field info for an entry type.
     *
     * @return list<array{handle: string, name: string, type: string, required: bool, instructions: string|null}>
     */
    public static function getEntryTypeFieldsExtended(EntryType $entryType): array {
        return array_values(array_map(
            fn (FieldInterface $field): array => [
                'handle' => $field->handle ?? '',
                'name' => $field->name ?? '',
                'type' => self::getFieldTypeName($field),
                'required' => $field->required ?? false,
                'instructions' => $field->instructions !== '' ? $field->instructions : null,
            ],
            $entryType->getFieldLayout()->getCustomFields(),
        ));
    }

    /**
     * Get the short class name for a field type.
     */
    public static function getFieldTypeName(FieldInterface $field): string {
        return (new ReflectionClass($field))->getShortName();
    }

    /**
     * Build entry type schema data.
     *
     * @return array{handle: string, name: string, hasTitleField: bool, fields: list<array{handle: string, name: string, type: string, required: bool}>}
     */
    public static function buildEntryTypeSchema(EntryType $entryType): array {
        return [
            'handle' => $entryType->handle ?? '',
            'name' => $entryType->name ?? '',
            'hasTitleField' => $entryType->hasTitleField,
            'fields' => self::getEntryTypeFields($entryType),
        ];
    }

    /**
     * Build section schema data.
     *
     * @return array{handle: string, name: string, type: string, hasUrls: bool, enableVersioning: bool}
     */
    public static function buildSectionSchema(Section $section): array {
        return [
            'handle' => $section->handle ?? '',
            'name' => $section->name ?? '',
            'type' => $section->type ?? 'channel',
            'hasUrls' => self::getSectionHasUrls($section),
            'enableVersioning' => $section->enableVersioning,
        ];
    }

    /**
     * Check if a section has URLs configured in any site.
     */
    private static function getSectionHasUrls(Section $section): bool {
        return array_any($section->getSiteSettings(), fn ($settings): bool => $settings->hasUrls);
    }

    /**
     * Collect unique fields across all entry types in a section.
     *
     * @return array<string, array{handle: string, type: string, searchable: bool}>
     */
    public static function collectSectionFields(Section $section): array {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();

        if ($entryTypes === []) {
            return [];
        }

        // Flatten all fields from all entry types into a single array
        $allFields = array_merge(...array_map(
            fn (EntryType $entryType): array => $entryType->getFieldLayout()->getCustomFields(),
            $entryTypes,
        ));

        // Reduce to unique fields by handle
        /** @var array<string, array{handle: string, type: string, searchable: bool}> $result */
        $result = array_reduce(
            $allFields,
            function (array $acc, FieldInterface $field): array {
                $handle = $field->handle ?? '';
                if ($handle !== '' && !isset($acc[$handle])) {
                    $acc[$handle] = [
                        'handle' => $handle,
                        'type' => self::getFieldTypeName($field),
                        'searchable' => $field->searchable,
                    ];
                }

                return $acc;
            },
            [],
        );

        return $result;
    }
}
