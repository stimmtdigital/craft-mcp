<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\resources;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Entries;
use DateTime;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use stimmt\craft\Mcp\attributes\McpResourceMeta;
use stimmt\craft\Mcp\completions\SectionHandleProvider;
use stimmt\craft\Mcp\enums\ResourceCategory;
use stimmt\craft\Mcp\support\SafeResourceExecution;

/**
 * MCP resources for Craft CMS entry content.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EntryResources {
    private const int DEFAULT_LIMIT = 50;

    /**
     * Get entries from a specific section.
     *
     * @return array{section: string, entries: list<array{id: int, title: string, slug: string, status: string, dateCreated: string|null, dateUpdated: string|null}>, total: int, limit: int}
     */
    #[McpResourceTemplate(
        uriTemplate: 'craft://entries/{section}',
        name: 'section-entries',
        description: 'List of entries in a specific section with basic metadata.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONTENT)]
    public function sectionEntries(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $section,
    ): array {
        return SafeResourceExecution::run(function () use ($section): array {
            if (!$this->sectionExists($section)) {
                throw new ResourceReadException("Section '{$section}' not found");
            }

            $entries = $this->fetchEntries($section);
            $total = $this->countEntries($section);

            return [
                'section' => $section,
                'entries' => array_values(array_map($this->buildEntrySummary(...), $entries)),
                'total' => $total,
                'limit' => self::DEFAULT_LIMIT,
            ];
        });
    }

    /**
     * Get a specific entry by section and slug.
     *
     * @return array{entry: array{id: int, title: string, slug: string, status: string, type: string, url: string|null, dateCreated: string|null, dateUpdated: string|null, fields: array<string, mixed>}}
     */
    #[McpResourceTemplate(
        uriTemplate: 'craft://entries/{section}/{slug}',
        name: 'entry-by-slug',
        description: 'Get a specific entry by its section handle and slug.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONTENT)]
    public function entryBySlug(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $section,
        string $slug,
    ): array {
        return SafeResourceExecution::run(function () use ($section, $slug): array {
            if (!$this->sectionExists($section)) {
                throw new ResourceReadException("Section '{$section}' not found");
            }

            $entry = $this->findEntryBySlug($section, $slug);
            if ($entry === null) {
                throw new ResourceReadException("Entry with slug '{$slug}' not found in section '{$section}'");
            }

            return [
                'entry' => $this->buildEntryDetail($entry),
            ];
        });
    }

    /**
     * Get entry statistics for a section.
     *
     * @return array{section: string, stats: array{total: int, live: int, disabled: int, pending: int, expired: int, drafts: int, byType: array<string, int>}}
     */
    #[McpResourceTemplate(
        uriTemplate: 'craft://entries/{section}/stats',
        name: 'section-stats',
        description: 'Get entry statistics for a specific section.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONTENT)]
    public function sectionStats(
        #[CompletionProvider(provider: SectionHandleProvider::class)]
        string $section,
    ): array {
        return SafeResourceExecution::run(function () use ($section): array {
            /** @var Entries $entriesService */
            $entriesService = Craft::$app->getEntries();

            /** @var Section|null $sectionObj */
            $sectionObj = $entriesService->getSectionByHandle($section);

            if ($sectionObj === null) {
                throw new ResourceReadException("Section '{$section}' not found");
            }

            return [
                'section' => $section,
                'stats' => $this->buildSectionStats($sectionObj),
            ];
        });
    }

    /**
     * Check if a section exists.
     */
    private function sectionExists(string $section): bool {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        return $entriesService->getSectionByHandle($section) !== null;
    }

    /**
     * Fetch entries from a section.
     *
     * @return list<Entry>
     */
    private function fetchEntries(string $section): array {
        /** @var Entry[] $entries */
        $entries = Entry::find()
            ->section($section)
            ->status(null)
            ->limit(self::DEFAULT_LIMIT)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        return array_values($entries);
    }

    /**
     * Count entries in a section.
     */
    private function countEntries(string $section, ?string $status = null): int {
        return (int) Entry::find()
            ->section($section)
            ->status($status)
            ->count();
    }

    /**
     * Find an entry by section and slug.
     */
    private function findEntryBySlug(string $section, string $slug): ?Entry {
        /** @var Entry|null $entry */
        $entry = Entry::find()
            ->section($section)
            ->slug($slug)
            ->status(null)
            ->one();

        return $entry;
    }

    /**
     * Build an entry summary for listing.
     *
     * @return array{id: int, title: string, slug: string, status: string, dateCreated: string|null, dateUpdated: string|null}
     */
    private function buildEntrySummary(Entry $entry): array {
        return [
            'id' => $entry->id ?? 0,
            'title' => $entry->title ?? '',
            'slug' => $entry->slug ?? '',
            'status' => $entry->status ?? '',
            'dateCreated' => $entry->dateCreated?->format('Y-m-d H:i:s'),
            'dateUpdated' => $entry->dateUpdated?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Build detailed entry data.
     *
     * @return array{id: int, title: string, slug: string, status: string, type: string, url: string|null, dateCreated: string|null, dateUpdated: string|null, fields: array<string, mixed>}
     */
    private function buildEntryDetail(Entry $entry): array {
        return [
            'id' => $entry->id ?? 0,
            'title' => $entry->title ?? '',
            'slug' => $entry->slug ?? '',
            'status' => $entry->status ?? '',
            'type' => $entry->getType()?->handle ?? '', // @phpstan-ignore nullsafe.neverNull
            'url' => $entry->url,
            'dateCreated' => $entry->dateCreated?->format('Y-m-d H:i:s'),
            'dateUpdated' => $entry->dateUpdated?->format('Y-m-d H:i:s'),
            'fields' => $this->extractFieldValues($entry),
        ];
    }

    /**
     * Extract field values from an entry.
     *
     * @return array<string, mixed>
     */
    private function extractFieldValues(Entry $entry): array {
        $fieldLayout = $entry->getFieldLayout();
        if ($fieldLayout === null) {
            return [];
        }

        $fields = [];

        /** @var FieldInterface[] $customFields */
        $customFields = $fieldLayout->getCustomFields();

        foreach ($customFields as $field) {
            $handle = $field->handle ?? '';
            if ($handle === '') {
                continue;
            }

            $value = $entry->getFieldValue($handle);
            $fields[$handle] = $this->serializeFieldValue($value);
        }

        return $fields;
    }

    /**
     * Build section statistics.
     *
     * @return array{total: int, live: int, disabled: int, pending: int, expired: int, drafts: int, byType: array<string, int>}
     */
    private function buildSectionStats(Section $section): array {
        $sectionHandle = $section->handle ?? '';

        return [
            'total' => $this->countEntries($sectionHandle),
            'live' => $this->countEntries($sectionHandle, 'live'),
            'disabled' => $this->countEntries($sectionHandle, 'disabled'),
            'pending' => $this->countEntries($sectionHandle, 'pending'),
            'expired' => $this->countEntries($sectionHandle, 'expired'),
            'drafts' => $this->countDrafts($sectionHandle),
            'byType' => $this->countByEntryType($section),
        ];
    }

    /**
     * Count drafts in a section.
     */
    private function countDrafts(string $section): int {
        return (int) Entry::find()
            ->section($section)
            ->drafts()
            ->count();
    }

    /**
     * Count entries by entry type.
     *
     * @return array<string, int>
     */
    private function countByEntryType(Section $section): array {
        /** @var EntryType[] $entryTypes */
        $entryTypes = $section->getEntryTypes();
        $sectionHandle = $section->handle ?? '';

        $counts = [];
        foreach ($entryTypes as $entryType) {
            $typeHandle = $entryType->handle ?? '';
            if ($typeHandle === '') {
                continue;
            }

            $counts[$typeHandle] = $this->countEntriesByType($sectionHandle, $typeHandle);
        }

        return $counts;
    }

    /**
     * Count entries by section and entry type.
     */
    private function countEntriesByType(string $section, string $type): int {
        return (int) Entry::find()
            ->section($section)
            ->type($type)
            ->status(null)
            ->count();
    }

    /**
     * Serialize a field value to a JSON-safe format.
     */
    private function serializeFieldValue(mixed $value): mixed {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof ElementQuery) {
            return $this->serializeElementQuery($value);
        }

        if (is_array($value)) {
            return array_map($this->serializeFieldValue(...), $value);
        }

        if (is_object($value)) {
            return ['_type' => $value::class];
        }

        return '[unsupported type]';
    }

    /**
     * Serialize an element query.
     *
     * @param ElementQuery<int, ElementInterface> $query
     * @return array{count: int, truncated: bool}|list<int>
     */
    private function serializeElementQuery(ElementQuery $query): array {
        /** @var list<int> $ids */
        $ids = $query->ids();
        $count = count($ids);

        if ($count <= 10) {
            return $ids;
        }

        return ['count' => $count, 'truncated' => true];
    }
}
