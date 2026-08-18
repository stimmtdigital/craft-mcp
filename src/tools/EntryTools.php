<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use DateTimeImmutable;
use Exception;
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
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\Presenter;
use stimmt\craft\Mcp\support\ResourceChangeNotifier;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\WriteParams;

/**
 * Entry tools: payload-format reads, draft-first writes, schema discovery.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryTools {
    /** Deepest nesting describe_entry_schema will expand. */
    private const int MAX_SCHEMA_DEPTH = 3;

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
        Authorization::scopeQuery($query);

        $results = $fields === null
            ? array_map(fn (Entry $entry): array => $this->reader->read($entry, $site), $query->all())
            : array_map(fn (Entry $entry): array => $this->projection->row($entry, $fields, $site), $query->all());

        return Response::paginated('entries', $results, (int) $query->count(), $limit, $offset);
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
        #[Schema(description: Presenter::OUTPUT_DESCRIPTION)]
        ResponseFormat $output = ResponseFormat::STRUCTURED,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($site);

        $query = Entry::find()->status($status === 'any' ? null : $status);
        foreach (['section' => $section, 'type' => $type, 'site' => $site, 'search' => $search] as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }

        $this->filters->apply($query, $filters, $relatedTo, $author, $updatedAfter, $updatedBefore, $createdAfter, $createdBefore, $site);
        Authorization::scopeQuery($query);

        $result = (new Buckets())->collect($query, $groupBy);
        $result['groupBy'] = $groupBy;

        return Response::success($result);
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
        $entry = $this->find($id, $slug, $section, $site);
        Authorization::assertCanView($entry);

        return Response::found('entry', $this->reader->read($entry, $site));
    }

    #[McpTool(
        name: 'create_entry',
        description: 'Create an entry. fields is JSON in the payload format (natural keys: {section,slug} for entries, {volume,filename} for assets, matrix blocks by type handle). Saves as a draft unless mode or the entryWriteMode setting says live. Use describe_entry_schema first to learn the shape.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
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
        ?string $postDate = null,
        ?string $expiryDate = null,
        ?RequestContext $context = null,
    ): array {
        $siteModel = SiteResolver::resolve($site);
        $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section)
            ?? throw new ToolCallException("Section '{$section}' not found");
        $entryType = $this->entryType($sectionModel, $type);

        // Authorization probe: an unsaved entry carrying the target
        // section/type/site is exactly what Craft's canSave inspects.
        Authorization::assertCanSave(new Entry([
            'sectionId' => $sectionModel->id,
            'typeId' => $entryType->id,
            'siteId' => $siteModel?->id ?? Craft::$app->getSites()->getPrimarySite()->id, // @phpstan-ignore nullsafe.neverNull
        ]));

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

        $attributes += WriteParams::schedule($postDate, $expiryDate);

        $result = $this->writer->create($attributes, WriteParams::fieldsPayload($fields), WriteParams::mode($mode), $site);

        if (!$result->isFailure() && $result->state === WriteMode::Live && $result->elementId !== null) {
            ResourceChangeNotifier::notifyEntry($context, $result->elementId);
        }

        return $result->isFailure()
            ? ['success' => false] + $result->toArray()
            : Response::success($result->toArray());
    }

    #[McpTool(
        name: 'update_entry',
        description: 'Update an entry by id. In draft mode (default) a live entry gets a draft on top; publish_entry applies it. fields is payload-format JSON; only supplied values change. Matrix-family blocks are entries too: pass a block\'s own id to edit just that block without touching its siblings. Pass expectedDateUpdated (the dateUpdated string get_entry returned) to fail instead of overwriting when the entry changed since your read.',
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
        ?string $postDate = null,
        ?string $expiryDate = null,
        ?string $expectedDateUpdated = null,
        ?RequestContext $context = null,
    ): array {
        $entry = $this->find($id, null, null, $site);
        Authorization::assertCanSave($entry);
        $this->assertUnchanged($entry, $expectedDateUpdated);

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

        $attributes += WriteParams::schedule($postDate, $expiryDate);

        $result = $this->writer->update($entry, $attributes, WriteParams::fieldsPayload($fields), WriteParams::mode($mode), $site);

        if (!$result->isFailure() && $result->state === WriteMode::Live && $result->elementId !== null) {
            ResourceChangeNotifier::notifyEntry($context, $result->elementId);
        }

        return $result->isFailure()
            ? ['success' => false] + $result->toArray()
            : Response::success($result->toArray());
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
        // Clamped rather than trusted: each level expands every block type of
        // every matrix field, so the payload and the work grow multiplicatively
        // and a caller passing a large number gets a response nothing can use,
        // after making the server do all of it. Two levels covers a matrix
        // inside a matrix, which is as deep as the payload contract goes.
        $depth = max(0, min($depth, self::MAX_SCHEMA_DEPTH));

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
            $entry = $this->example($example, $sectionModel->handle);
            Authorization::assertCanView($entry);
            $schema['example'] = $this->reader->read($entry);
        }

        return $schema;
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

    /**
     * Optional optimistic-concurrency precondition (#36): a read-modify-write
     * payload built from a get_entry snapshot is rejected when the canonical
     * entry changed underneath it, instead of silently overwriting whatever
     * changed. Timestamps compare as instants so timezone formatting
     * differences never produce false conflicts; omitted means unchecked.
     */
    private function assertUnchanged(Entry $entry, ?string $expectedDateUpdated): void {
        if ($expectedDateUpdated === null) {
            return;
        }

        // Compared against the element the caller addressed, not its canonical.
        // The default flow hands back a derivative draft's id, get_entry on that
        // id reports the draft's own dateUpdated, and comparing that to the
        // canonical's could never match: the draft was saved when it was
        // created and the canonical was not touched. The tool then told the
        // agent to re-read and retry, which produced the same timestamp and the
        // same refusal, with no way out. It also watched the wrong thing in both
        // directions, missing a concurrent edit of the draft (the actual race
        // for draft-first content) while blocking on canonical changes that
        // could not conflict. Addressing the canonical is unchanged, and so is
        // an unpublished draft, whose getCanonical() already returns itself.
        $current = $entry->dateUpdated;
        if ($current === null || $current->getTimestamp() === $this->timestamp($expectedDateUpdated)) {
            return;
        }

        throw new ToolCallException(
            "Entry {$entry->id} changed since you read it: dateUpdated is now"
            . " '{$current->format('Y-m-d H:i:s')}', expectedDateUpdated was '{$expectedDateUpdated}'."
            . ' Re-read the entry with get_entry and rebuild the write from the fresh payload.',
        );
    }

    private function timestamp(string $value): int {
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Exception) {
            throw new ToolCallException(
                "Invalid expectedDateUpdated '{$value}'; pass the dateUpdated string exactly as get_entry returned it.",
            );
        }
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
