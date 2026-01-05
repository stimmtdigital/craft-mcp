<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SafeExecution;
use stimmt\craft\Mcp\support\Serializer;

/**
 * Entry-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryTools {
    /**
     * List entries with optional filters.
     */
    #[McpTool(
        name: 'list_entries',
        description: 'List entries from Craft CMS. Filter by section, type, status, limit, offset. Returns entry data including custom fields.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listEntries(
        ?string $section = null,
        ?string $type = null,
        ?string $status = null,
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $status, $limit, $offset): array {
            $query = Entry::find()
                ->limit($limit)
                ->offset($offset);

            $this->applyFilters($query, [
                'section' => $section,
                'type' => $type,
                'status' => $status ?? null, // null = all statuses
            ]);

            $entries = $query->all();
            $results = array_map($this->serializeEntry(...), $entries);

            return Response::paginated('entries', $results, (int) $query->count(), $limit, $offset);
        });
    }

    /**
     * Get a single entry by ID or slug.
     */
    #[McpTool(
        name: 'get_entry',
        description: 'Get a single entry by ID or slug. Returns full entry data including all custom fields.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getEntry(?int $id = null, ?string $slug = null, ?string $section = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $slug, $section): array {
            if ($id === null && $slug === null) {
                throw new ToolCallException('Either id or slug must be provided');
            }

            $query = Entry::find()->status(null);
            $this->applyFilters($query, [
                'id' => $id,
                'slug' => $slug,
                'section' => $section,
            ]);

            $entry = $query->one();

            if ($entry === null) {
                throw new ToolCallException('Entry not found');
            }

            return Response::found('entry', $this->serializeEntry($entry));
        });
    }

    /**
     * Create a new entry.
     */
    #[McpTool(
        name: 'create_entry',
        description: 'Create a new entry in Craft CMS. Requires section handle, entry type handle, title, and optionally custom field values as JSON.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createEntry(
        string $section,
        string $type,
        string $title,
        ?string $slug = null,
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $title, $slug, $fields): array {
            $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);
            if ($sectionModel === null) {
                throw new ToolCallException("Section '{$section}' not found");
            }

            $entryType = $this->findEntryType($sectionModel, $type);
            if ($entryType === null) {
                throw new ToolCallException("Entry type '{$type}' not found in section '{$section}'");
            }

            $fieldValues = $this->parseFieldsJson($fields);
            if ($fieldValues === false) {
                throw new ToolCallException('Invalid JSON in fields parameter');
            }

            $entry = new Entry();
            $entry->sectionId = $sectionModel->id;
            $entry->typeId = $entryType->id;
            $entry->title = $title;
            $entry->slug = $slug;
            $entry->authorId = $this->getAuthorId();

            if ($fieldValues !== null) {
                $entry->setFieldValues($fieldValues);
            }

            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new ToolCallException('Failed to save entry: ' . json_encode($entry->getErrors()));
            }

            return Response::success(['entry' => $this->serializeEntry($entry)]);
        });
    }

    /**
     * Update an existing entry.
     */
    #[McpTool(
        name: 'update_entry',
        description: 'Update an existing entry by ID. Can update title, slug, status, and custom field values (as JSON).',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateEntry(
        int $id,
        ?string $title = null,
        ?string $slug = null,
        ?string $status = null,
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $title, $slug, $status, $fields): array {
            $entry = Entry::find()->id($id)->status(null)->one();

            if ($entry === null) {
                throw new ToolCallException("Entry with ID {$id} not found");
            }

            $fieldValues = $this->parseFieldsJson($fields);
            if ($fieldValues === false) {
                throw new ToolCallException('Invalid JSON in fields parameter');
            }

            if ($title !== null) {
                $entry->title = $title;
            }
            if ($slug !== null) {
                $entry->slug = $slug;
            }
            if ($status !== null) {
                $entry->enabled = ($status === 'live' || $status === 'enabled');
            }
            if ($fieldValues !== null) {
                $entry->setFieldValues($fieldValues);
            }

            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new ToolCallException('Failed to save entry: ' . json_encode($entry->getErrors()));
            }

            return Response::success(['entry' => $this->serializeEntry($entry)]);
        });
    }

    /**
     * Apply non-null filters to a query.
     */
    private function applyFilters(mixed $query, array $filters): void {
        foreach ($filters as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }
    }

    /**
     * Find entry type by handle in section.
     */
    private function findEntryType(mixed $section, string $handle): ?object {
        foreach ($section->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        return null;
    }

    /**
     * Parse fields JSON parameter.
     *
     * @return array|null|false null if empty, false if invalid JSON, array if valid
     */
    private function parseFieldsJson(?string $fields): array|null|false {
        if ($fields === null) {
            return null;
        }

        $decoded = json_decode($fields, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
    }

    /**
     * Get author ID for new entries.
     */
    private function getAuthorId(): ?int {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            $user = User::find()->admin()->one();
        }

        return $user?->id;
    }

    /**
     * Serialize an entry to array.
     */
    private function serializeEntry(Entry $entry): array {
        $data = [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'sectionId' => $entry->sectionId,
            'sectionHandle' => $entry->getSection()?->handle,
            'typeId' => $entry->typeId,
            'typeHandle' => $entry->getType()?->handle,
            'authorId' => $entry->authorId,
            'postDate' => $entry->postDate?->format('Y-m-d H:i:s'),
            'expiryDate' => $entry->expiryDate?->format('Y-m-d H:i:s'),
            'dateCreated' => $entry->dateCreated?->format('Y-m-d H:i:s'),
            'dateUpdated' => $entry->dateUpdated?->format('Y-m-d H:i:s'),
            'url' => $entry->getUrl(),
        ];

        $fieldLayout = $entry->getFieldLayout();
        if ($fieldLayout !== null) {
            $data['fields'] = $this->serializeFields($entry, $fieldLayout);
        }

        return $data;
    }

    /**
     * Serialize custom field values.
     */
    private function serializeFields(Entry $entry, mixed $fieldLayout): array {
        $fieldValues = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            $fieldValues[$field->handle] = Serializer::serialize($entry->getFieldValue($field->handle));
        }

        return $fieldValues;
    }
}
