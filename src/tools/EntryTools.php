<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\elements\query\Buckets;
use stimmt\craft\Mcp\elements\query\Filters;
use stimmt\craft\Mcp\elements\query\Projection;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\schema\Describer;
use stimmt\craft\Mcp\elements\schema\Meta;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SafeExecution;
use stimmt\craft\Mcp\support\SiteResolver;

/**
 * Entry tools: payload-format reads, draft-first writes, schema discovery.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryTools {
    private readonly Reader $reader;

    private readonly Writer $writer;

    private readonly Keys $keys;

    private readonly Filters $filters;

    private readonly Projection $projection;

    public function __construct(?Reader $reader = null, ?Writer $writer = null, ?Keys $keys = null, ?Filters $filters = null, ?Projection $projection = null) {
        $this->reader = $reader ?? ElementModule::reader();
        $this->writer = $writer ?? ElementModule::writer();
        $this->keys = $keys ?? new Keys();
        $this->filters = $filters ?? new Filters();
        $this->projection = $projection ?? new Projection($this->reader);
    }

    #[McpTool(
        name: 'list_entries',
        description: 'List entries. Filter by section, type, status, site, full-text search, field values (with :empty:/:notempty: and natural keys for relations), relatedTo, author, and date ranges. Returns entries in the payload format (natural keys for relations).',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listEntries(
        ?string $section = null,
        ?string $type = null,
        ?string $status = null,
        ?string $site = null,
        ?string $search = null,
        #[Schema(type: 'object', description: 'Field-value filters: {fieldHandle: value}. Values are scalars, ":empty:", ":notempty:", or a natural key object for relation fields (e.g. {"group": "...", "slug": "..."}).', additionalProperties: true)]
        ?array $filters = null,
        #[Schema(type: 'object', description: 'Only entries related to this element, as a natural key: {"section","slug"}, {"volume","filename"}, {"group","slug"}, or {"username"}.', additionalProperties: true)]
        ?array $relatedTo = null,
        ?string $author = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
        #[Schema(type: 'array', description: 'Projection: return only these attributes and field handles per entry (id and title always included) instead of the full payload. Ideal for scanning many entries.', items: ['type' => 'string'])]
        ?array $fields = null,
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $status, $site, $search, $filters, $relatedTo, $author, $updatedAfter, $updatedBefore, $createdAfter, $createdBefore, $fields, $limit, $offset): array {
            SiteResolver::resolve($site);

            $query = Entry::find()->limit($limit)->offset($offset);

            if ($status !== null) {
                $query->status($status === 'any' ? null : $status);
            }

            foreach (['section' => $section, 'type' => $type, 'site' => $site, 'search' => $search] as $method => $value) {
                if ($value !== null) {
                    $query->$method($value);
                }
            }

            $this->filters->apply($query, $filters, $relatedTo, $author, $updatedAfter, $updatedBefore, $createdAfter, $createdBefore, $site);

            $results = $fields === null
                ? array_map(fn (Entry $entry): array => $this->reader->read($entry, $site), $query->all())
                : array_map(fn (Entry $entry): array => $this->projection->row($entry, $fields, $site), $query->all());

            return Response::paginated('entries', $results, (int) $query->count(), $limit, $offset);
        });
    }

    #[McpTool(
        name: 'count_entries',
        description: 'Count entries, optionally grouped: by attribute (status, type, section, site, author), by date bucket ("month:dateUpdated", day|week|month|year with dateCreated|dateUpdated|postDate), or by a field handle (relation fields bucket by related title, empty values under "(empty)"). Same filters as list_entries. Counts include EVERY status by default (list_entries defaults to live only); pass status to narrow. One call answers "how many per X" without listing anything.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function countEntries(
        ?string $section = null,
        ?string $type = null,
        ?string $status = null,
        ?string $site = null,
        ?string $search = null,
        #[Schema(type: 'object', description: 'Field-value filters: {fieldHandle: value}. Values are scalars, ":empty:", ":notempty:", or a natural key object for relation fields.', additionalProperties: true)]
        ?array $filters = null,
        #[Schema(type: 'object', description: 'Only entries related to this element, as a natural key.', additionalProperties: true)]
        ?array $relatedTo = null,
        ?string $author = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
        ?string $groupBy = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $status, $site, $search, $filters, $relatedTo, $author, $updatedAfter, $updatedBefore, $createdAfter, $createdBefore, $groupBy): array {
            SiteResolver::resolve($site);

            $query = Entry::find()->status($status === 'any' ? null : $status);
            foreach (['section' => $section, 'type' => $type, 'site' => $site, 'search' => $search] as $method => $value) {
                if ($value !== null) {
                    $query->$method($value);
                }
            }

            $this->filters->apply($query, $filters, $relatedTo, $author, $updatedAfter, $updatedBefore, $createdAfter, $createdBefore, $site);

            $result = (new Buckets())->collect($query, $groupBy);
            $result['groupBy'] = $groupBy;

            return Response::success($result);
        });
    }

    #[McpTool(
        name: 'get_entry',
        description: 'Get one entry by id or slug, in the payload format: what this returns is exactly what create_entry/update_entry accept as fields.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getEntry(
        ?int $id = null,
        ?string $slug = null,
        ?string $section = null,
        ?string $site = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $slug, $section, $site): array {
            $entry = $this->find($id, $slug, $section, $site);

            return Response::found('entry', $this->reader->read($entry, $site));
        });
    }

    #[McpTool(
        name: 'create_entry',
        description: 'Create an entry. fields is JSON in the payload format (natural keys: {section,slug} for entries, {volume,filename} for assets, matrix blocks by type handle). Saves as a draft unless mode or the entryWriteMode setting says live. Use describe_entry_schema first to learn the shape.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createEntry(
        string $section,
        string $type,
        string $title,
        ?string $slug = null,
        ?string $site = null,
        ?string $fields = null,
        ?string $mode = null,
        ?string $parent = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $title, $slug, $site, $fields, $mode, $parent): array {
            SiteResolver::resolve($site);
            $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section)
                ?? throw new ToolCallException("Section '{$section}' not found");
            $entryType = $this->entryType($sectionModel, $type);

            $attributes = [
                'type' => Entry::class,
                'sectionId' => $sectionModel->id,
                'typeId' => $entryType->id,
                'title' => $title,
                'slug' => $slug,
                'authorId' => $this->authorId(),
            ];

            $parentId = $this->parentId($parent, $section, $site);
            if ($parentId !== null) {
                $attributes['parentId'] = $parentId;
            }

            $result = $this->writer->create($attributes, $this->fieldsPayload($fields), $this->mode($mode), $site);

            return $result->isFailure()
                ? ['success' => false] + $result->toArray()
                : Response::success($result->toArray());
        });
    }

    #[McpTool(
        name: 'update_entry',
        description: 'Update an entry by id. In draft mode (default) a live entry gets a draft on top; publish_entry applies it. fields is payload-format JSON; only supplied values change.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateEntry(
        int $id,
        ?string $site = null,
        ?string $title = null,
        ?string $slug = null,
        ?string $status = null,
        ?string $fields = null,
        ?string $mode = null,
        ?string $parent = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $site, $title, $slug, $status, $fields, $mode, $parent): array {
            $entry = $this->find($id, null, null, $site);

            $attributes = array_filter([
                'title' => $title,
                'slug' => $slug,
            ], static fn (?string $v): bool => $v !== null);

            if ($status !== null) {
                $attributes['enabled'] = in_array($status, ['live', 'enabled'], true);
            }

            $parentId = $this->parentId($parent, $entry->getSection()?->handle, $site);
            if ($parentId !== null) {
                $attributes['parentId'] = $parentId;
            }

            $result = $this->writer->update($entry, $attributes, $this->fieldsPayload($fields), $this->mode($mode), $site);

            return $result->isFailure()
                ? ['success' => false] + $result->toArray()
                : Response::success($result->toArray());
        });
    }

    #[McpTool(
        name: 'describe_entry_schema',
        description: 'Describe the fields a section/entry type accepts: handles, kinds, required flags, a per-field input shape (the exact payload each field takes: natural keys for relations, block types for matrix, link/option/table/container shapes for structured and third-party fields), native fields, writable meta attributes. Pass example (entry id or slug) to include a real entry payload as a golden fixture.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function describeEntrySchema(
        string $section,
        ?string $type = null,
        int $depth = 1,
        ?string $example = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($section, $type, $depth, $example): array {
            $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section)
                ?? throw new ToolCallException("Section '{$section}' not found");
            $entryType = $this->entryType($sectionModel, $type ?? $sectionModel->getEntryTypes()[0]->handle);

            Craft::$app->getFields()->refreshFields();

            $describer = new Describer();
            $meta = new Meta();
            $layout = $entryType->getFieldLayout();

            $schema = [
                'section' => $sectionModel->handle,
                'type' => $entryType->handle,
                'flags' => $meta->entryFlags($entryType),
                'meta' => $meta->writable(new Entry(['typeId' => $entryType->id])),
                'natives' => $describer->natives($layout),
                'fields' => $describer->describe($layout, $depth),
            ];

            if ($example !== null) {
                $schema['example'] = $this->reader->read($this->example($example, $sectionModel->handle));
            }

            return $schema;
        });
    }

    private function find(?int $id, ?string $slug, ?string $section, ?string $site): Entry {
        return $this->lookup($id, $slug, $section, $site) ?? throw new ToolCallException('Entry not found');
    }

    private function lookup(?int $id, ?string $slug, ?string $section, ?string $site): ?Entry {
        if ($id === null && $slug === null) {
            throw new ToolCallException('Either id or slug must be provided');
        }

        SiteResolver::resolve($site);

        $query = Entry::find()->status(null);
        if ($id !== null) {
            // An id lookup must find drafts and revisions too: agents read
            // back the draft a write just created, and revision ids come from
            // list_revisions. null matches both states.
            $query->drafts(null)->revisions(null);
        }

        foreach (['id' => $id, 'slug' => $slug, 'section' => $section, 'site' => $site] as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }

        return $query->one();
    }

    private function example(string $example, string $section): Entry {
        $byId = is_numeric($example) ? $this->lookup((int) $example, null, $section, null) : null;

        // Numeric-looking values that match no id fall back to a slug lookup.
        return $byId ?? $this->find(null, $example, $section, null);
    }

    private function entryType(mixed $section, string $handle): object {
        foreach ($section->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        throw new ToolCallException("Entry type '{$handle}' not found in section '{$section->handle}'");
    }

    private function fieldsPayload(?string $fields): array {
        if ($fields === null) {
            return [];
        }

        $decoded = json_decode($fields, true);
        if (!is_array($decoded)) {
            throw new ToolCallException('Invalid JSON in fields parameter');
        }

        return $decoded;
    }

    private function mode(?string $mode): WriteMode {
        if ($mode !== null) {
            return WriteMode::tryFrom(strtolower($mode))
                ?? throw new ToolCallException("Unknown mode '{$mode}'; use draft or live");
        }

        $settings = Mcp::settings();

        return WriteMode::fromSetting($settings->entryWriteMode);
    }

    private function parentId(?string $parent, ?string $section, ?string $site): ?int {
        if ($parent === null) {
            return null;
        }

        if (is_numeric($parent)) {
            return (int) $parent;
        }

        $id = $section === null ? null : $this->keys->idFor(Entry::class, ['section' => $section, 'slug' => $parent], $site);

        return $id ?? throw new ToolCallException("Parent entry '{$parent}' not found");
    }

    private function authorId(): ?int {
        $user = Craft::$app->getUser()->getIdentity() ?? User::find()->admin()->one();

        return $user?->id;
    }
}
