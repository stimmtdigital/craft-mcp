<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use DateTimeImmutable;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\elements\query\Buckets;
use stimmt\craft\Mcp\elements\query\Filters;
use stimmt\craft\Mcp\elements\query\Projection;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Resolution;
use stimmt\craft\Mcp\elements\schema\Describer;
use stimmt\craft\Mcp\elements\schema\Meta;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\pipeline\Presenter;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\EntryResolver;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\ResourceChangeNotifier;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\Window;
use stimmt\craft\Mcp\support\WriteParams;

/**
 * Entry tools: payload-format reads, draft-first writes, schema discovery.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryTools {
    /** Deepest nesting describe_entry_schema will expand. */
    private const int MAX_SCHEMA_DEPTH = 3;

    /** Craft's own "in any section", which is the set nested blocks are not in. */
    private const string ANY_SECTION = '*';

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
        title: 'Browse entries',
        description: 'List entries. Filter by section, type, status, site, full-text search, field values (with :empty:/:notempty: and natural keys for relations), relatedTo, author, and date ranges. Returns entries in the payload format (natural keys for relations). Lists top-level entries: a Matrix block is an entry too, but it belongs to no section, so blocks are left out unless includeNested.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listEntries(
        #[Schema(description: 'Section handle to list from; list_sections reports the handles. Omit to list across every section, which is every top-level entry the install has.')]
        ?string $section = null,
        #[Schema(description: 'List nested Matrix blocks alongside top-level entries. A block is an entry in Craft, but it belongs to no section and carries no title or slug of its own, so blocks are left out by default; pass true to audit block content across owners. Has no effect when section is set, because no block is in a section. To read one block, call get_entry with the block\'s own id.')]
        bool $includeNested = false,
        #[Schema(description: 'Entry type handle within the section, as describe_entry_schema reports it.')]
        ?string $type = null,
        #[Schema(description: 'Entry status: live, pending, expired, disabled, or "any" for every state. Omitted lists live entries only.')]
        ?string $status = null,
        #[Schema(description: 'Site handle to read from; list_sites reports the handles. Omitted uses Craft\'s current site.')]
        ?string $site = null,
        #[Schema(description: 'Full-text search over the entry\'s searchable content, in Craft\'s own search syntax.')]
        ?string $search = null,
        #[Schema(type: 'object', description: 'Field-value filters: {fieldHandle: value}. Values are scalars, ":empty:", ":notempty:", or a natural key object for relation fields (e.g. {"group": "...", "slug": "..."}).', additionalProperties: true)]
        ?array $filters = null,
        #[Schema(type: 'object', description: 'Only entries related to this element, as a natural key: {"section","slug"}, {"volume","filename"}, {"group","slug"}, or {"username"}.', additionalProperties: true)]
        ?array $relatedTo = null,
        #[Schema(description: 'Author username or email address.')]
        ?string $author = null,
        #[Schema(description: 'Lower bound on dateUpdated, inclusive. Any date Craft parses, such as "2026-01-31" or "2026-01-31 14:00".')]
        ?string $updatedAfter = null,
        #[Schema(description: 'Upper bound on dateUpdated, exclusive. Same date formats as updatedAfter.')]
        ?string $updatedBefore = null,
        #[Schema(description: 'Lower bound on dateCreated, inclusive. Same date formats as updatedAfter.')]
        ?string $createdAfter = null,
        #[Schema(description: 'Upper bound on dateCreated, exclusive. Same date formats as updatedAfter.')]
        ?string $createdBefore = null,
        #[Schema(type: 'array', description: 'Projection: return only these attributes and field handles per entry (id and title always included) instead of the full payload. Ideal for scanning many entries.', items: ['type' => 'string'])]
        ?array $fields = null,
        #[Schema(description: Window::LIMIT_DESCRIPTION . ' count_entries answers a total without listing rows.', minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($site);
        $this->assertScope($section, $type, $status);
        $this->assertTypeInScope($type, $section, $includeNested);
        Window::assert($limit, $offset);

        $query = Entry::find()->limit($limit)->offset($offset);
        $this->scopeToSections($query, $section, $includeNested);

        if ($status !== null) {
            $query->status($status === 'any' ? null : $status);
        }

        foreach (['type' => $type, 'site' => $site, 'search' => $search] as $method => $value) {
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

    /**
     * Only the parameters that mean something different here carry a
     * description: status, whose default is the opposite of list_entries', and
     * groupBy, which list_entries does not have. The rest are the same filter
     * set, spelled out on list_entries, which this tool's own description
     * points the caller at rather than restating word for word.
     */
    #[McpTool(
        name: 'count_entries',
        title: 'Count and group entries',
        description: 'Count entries, optionally grouped: by attribute (status, type, section, site, author), by date bucket ("month:dateUpdated", day|week|month|year with dateCreated|dateUpdated|postDate), or by a field handle (relation fields bucket by related title, empty values under "(empty)"). Same filters as list_entries, and the same top-level scope: nested Matrix blocks are left out unless includeNested, so a total here matches the one list_entries reports. Counts include EVERY status by default (list_entries defaults to live only); pass status to narrow. One call answers "how many per X" without listing anything.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function countEntries(
        #[Schema(description: 'Section handle to count within; list_sections reports the handles. Omit to count across every section, which is every top-level entry the install has.')]
        ?string $section = null,
        #[Schema(description: 'Count nested Matrix blocks alongside top-level entries, exactly as in list_entries. Left out by default, so the two tools answer about the same set of entries.')]
        bool $includeNested = false,
        ?string $type = null,
        #[Schema(description: 'Entry status: live, pending, expired, disabled, or "any". Omitted counts EVERY status, unlike list_entries.')]
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
        #[Schema(description: 'What to bucket the count by: an attribute (status, type, section, site, author), a date bucket such as "month:dateUpdated" (day|week|month|year with dateCreated|dateUpdated|postDate), or a field handle. Omit for a plain total.')]
        ?string $groupBy = null,
        #[Schema(description: Presenter::OUTPUT_DESCRIPTION)]
        ResponseFormat $output = ResponseFormat::STRUCTURED,
        ?RequestContext $context = null,
    ): array {
        SiteResolver::resolve($site);
        $this->assertScope($section, $type, $status);
        $this->assertTypeInScope($type, $section, $includeNested);

        $query = Entry::find()->status($status === 'any' ? null : $status);
        $this->scopeToSections($query, $section, $includeNested);

        foreach (['type' => $type, 'site' => $site, 'search' => $search] as $method => $value) {
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
        title: 'Read one entry',
        description: 'Get one entry by id or slug, in the payload format: what this returns is exactly what create_entry/update_entry accept as fields.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getEntry(
        #[Schema(description: 'Element id. Reads a canonical entry, a draft (the draftElementId a write returned), a revision (a revisionElementId from list_revisions), or a single Matrix block by its own id.')]
        ?int $id = null,
        #[Schema(description: 'Entry slug, as an alternative to id. Pass section too when the same slug exists in more than one section.')]
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
        title: 'Create an entry',
        description: 'Create an entry. fields is JSON in the payload format (natural keys: {section,slug} for entries, {volume,filename} for assets, matrix blocks by type handle). Saves as a draft unless mode or the entryWriteMode setting says live. Use describe_entry_schema first to learn the shape.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function createEntry(
        #[Schema(description: 'Handle of the section to create the entry in; list_sections reports the handles.')]
        string $section,
        #[Schema(description: 'Entry type handle allowed in that section; describe_entry_schema reports them.')]
        string $type,
        string $title,
        #[Schema(description: 'URL slug. Omit to let Craft derive one from the title.')]
        ?string $slug = null,
        #[Schema(description: 'Site handle to create the entry on; list_sites reports the handles.')]
        ?string $site = null,
        #[Schema(description: 'Custom field values as a JSON-encoded STRING (not a nested object), in the payload format describe_entry_schema documents per field.')]
        ?string $fields = null,
        #[Schema(description: '"draft" stages the write for review, "live" writes the canonical entry directly. Omitted follows the entryWriteMode setting, which defaults to draft.')]
        ?string $mode = null,
        #[Schema(description: 'Parent entry in a structure section, as its numeric id or as its slug within the same section.')]
        ?string $parent = null,
        #[Schema(description: 'When the entry goes live. Any date Craft parses; a bare timestamp is read in the system timezone.')]
        ?string $postDate = null,
        #[Schema(description: 'When the entry stops being live. Same date formats as postDate.')]
        ?string $expiryDate = null,
        ?RequestContext $context = null,
    ): array {
        $siteModel = SiteResolver::resolve($site);
        $sectionModel = HandleResolver::section($section);
        $entryType = HandleResolver::entryType($type, $sectionModel);

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
            ? Response::failure($result->toArray())
            : Response::success($result->toArray());
    }

    #[McpTool(
        name: 'update_entry',
        title: 'Update an entry',
        description: 'Update an entry by id. In draft mode (default) a live entry gets a draft on top; publish_entry applies it. fields is payload-format JSON; only supplied values change. Matrix-family blocks are entries too: pass a block\'s own id to edit just that block without touching its siblings. Pass expectedDateUpdated (the dateUpdated string get_entry returned) to fail instead of overwriting when the entry changed since your read.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function updateEntry(
        #[Schema(description: 'Element id to write: a canonical entry, a draft (draftElementId), or a single Matrix block by its own entry id. A revisionElementId is refused, naming the canonical id to write instead.')]
        int $id,
        #[Schema(description: 'Site handle whose content to write; list_sites reports the handles.')]
        ?string $site = null,
        ?string $title = null,
        ?string $slug = null,
        #[Schema(description: '"live" or "enabled" enables the entry; any other value disables it.')]
        ?string $status = null,
        #[Schema(description: 'Custom field values as a JSON-encoded STRING (not a nested object), in the payload format describe_entry_schema documents per field. Only the handles present are written.')]
        ?string $fields = null,
        #[Schema(description: '"draft" stages the write on a draft of the entry, "live" writes the canonical entry directly. Omitted follows the entryWriteMode setting, which defaults to draft.')]
        ?string $mode = null,
        #[Schema(description: 'New parent entry in a structure section, as its numeric id or as its slug within the same section.')]
        ?string $parent = null,
        #[Schema(description: 'When the entry goes live. Any date Craft parses; a bare timestamp is read in the system timezone.')]
        ?string $postDate = null,
        #[Schema(description: 'When the entry stops being live. Same date formats as postDate.')]
        ?string $expiryDate = null,
        #[Schema(description: 'Concurrency guard: the dateUpdated string get_entry returned for this same id. The write is refused, naming both timestamps, if the element changed since that read.')]
        ?string $expectedDateUpdated = null,
        ?RequestContext $context = null,
    ): array {
        $entry = $this->find($id, null, null, $site);
        EntryResolver::assertWritable($entry, 'update_entry');
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
            ? Response::failure($result->toArray())
            : Response::success($result->toArray());
    }

    #[McpTool(
        name: 'describe_entry_schema',
        title: 'Entry field reference',
        description: 'Describe the fields a section/entry type accepts: handles, kinds, required flags, a per-field input shape (the exact payload each field takes: natural keys for relations, block types for matrix, link/option/table/container shapes for structured and third-party fields), native fields, writable meta attributes. On a multi-site install each field also reports its translation method and whether it holds a separate value per site, which is what says if writing it on one site changes another. Pass example (entry id or slug) to include a real entry payload as a golden fixture.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function describeEntrySchema(
        string $section,
        #[Schema(description: 'Entry type handle to describe. Omit for the section\'s first entry type.')]
        ?string $type = null,
        #[Schema(description: 'How many levels of nested Matrix block types to expand. Clamped to 3, because each level expands every block type of every Matrix field.')]
        int $depth = 1,
        #[Schema(description: 'An existing entry in this section, by id or slug. Its full payload comes back as a golden fixture to copy.')]
        ?string $example = null,
        ?RequestContext $context = null,
    ): array {
        // Clamped rather than trusted: each level expands every block type of
        // every matrix field, so the payload and the work grow multiplicatively
        // and a caller passing a large number gets a response nothing can use,
        // after making the server do all of it. Two levels covers a matrix
        // inside a matrix, which is as deep as the payload contract goes.
        $depth = max(0, min($depth, self::MAX_SCHEMA_DEPTH));

        $sectionModel = HandleResolver::section($section);
        $entryType = HandleResolver::entryType($type ?? $sectionModel->getEntryTypes()[0]->handle, $sectionModel);

        Craft::$app->getFields()->refreshFields();

        $describer = new Describer(multiSite: Craft::$app->getIsMultiSite());
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

    /**
     * The one entry an address names, or null when it names none.
     *
     * WHY null is not the only failure it can report: an address that matches
     * SEVERAL entries has no answer either, but it is a different mistake, and
     * `->one()` could only report the first row as if it were the only one.
     * `{slug, section}` is not a unique address, exactly as Keys says of the
     * natural key built from the same two parts, so the several-matches case
     * refuses here instead of guessing. A miss stays nullable because
     * example() reads it as "not an id, try the slug".
     */
    private function lookup(?int $id, ?string $slug, ?string $section, ?string $site): ?Entry {
        if ($id === null && $slug === null) {
            throw new ToolCallException('Either id or slug must be provided');
        }

        SiteResolver::resolve($site);

        $query = Entry::find()->status(null);
        if ($id !== null) {
            // An id lookup must find drafts and revisions too: agents read
            // back the draft a write just created, and revision ids come from
            // list_revisions. null matches both states. Writes reject the
            // revision afterwards (assertWritable); finding it is what lets
            // that refusal name the canonical id, which a miss could not.
            $query->drafts(null)->revisions(null);
        }

        foreach (['id' => $id, 'slug' => $slug, 'section' => $section, 'site' => $site] as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }

        // Two rows is all it takes to know the address was not specific
        // enough, and stopping there keeps the common single-match case flat.
        $matches = $query->limit(2)->all();
        if (count($matches) > 1) {
            throw $this->ambiguousSlug($slug, $section);
        }

        return $matches[0] ?? null;
    }

    /**
     * Reuses Resolution's account of "matched too much" rather than writing a
     * second one: the condition is the same one Keys reports for a natural
     * key, and an agent that learns the rule from one tool should read the
     * same rule from the other.
     */
    private function ambiguousSlug(?string $slug, ?string $section): ToolCallException {
        $where = $section === null ? '' : " in section '{$section}'";
        $narrow = $section === null
            ? 'Pass section to narrow the lookup, or use list_entries to find the id you want.'
            : 'Use list_entries to find the id you want.';

        return new ToolCallException(
            Resolution::ambiguous()->explain('entry')
            . ". Slug '{$slug}'{$where} matches several entries, because a slug is unique per site, not per section. "
            . $narrow,
        );
    }

    /**
     * The section scope of a read, which is also where the line between
     * top-level entries and nested Matrix blocks is drawn.
     *
     * WHY a section-less listing is not simply an unfiltered query: in Craft 5
     * a Matrix block IS an entry, so Entry::find() hands back blocks beside the
     * content that was asked for. On the install this was found on, 45 of 105
     * rows were title-less, slug-less blocks belonging to no section, and they
     * sorted first, so the first page of "list entries across every section"
     * contained no entry a human would call one. An agent asking that question
     * means top-level content; blocks are reached through their owner, or by
     * their own id, and includeNested is there for the audit that really does
     * want them.
     *
     * The switch is Craft's own rather than a hand-rolled sectionId filter:
     * '*' means "in any section", and a block is in none. It also refuses the
     * whole query when the install has no sections at all, which is the right
     * answer to "every top-level entry" there.
     *
     * Both read tools call this, so count_entries can never drift from
     * list_entries about what it is counting.
     */
    private function scopeToSections(EntryQuery $query, ?string $section, bool $includeNested): void {
        if ($section !== null) {
            $query->section($section);

            return;
        }

        // Nothing to add: with no section param at all, Craft returns blocks
        // and top-level entries alike, which is exactly what was asked for.
        if ($includeNested) {
            return;
        }

        $query->section(self::ANY_SECTION);
    }

    /**
     * Refuse an entry type no section has, while blocks are out of scope.
     *
     * WHY: leaving blocks out makes the honest answer to "list entries of type
     * X" zero rows whenever X is a Matrix block type, and a bare 0 is the
     * confident wrong answer this tool already refuses to give about a section
     * the install does not have. The type is real; the scope simply does not
     * reach it, so the refusal names the parameter that does. An entry type
     * used by a section AND by a Matrix field passes: the top-level entries
     * having it are exactly what the caller asked for.
     */
    private function assertTypeInScope(?string $type, ?string $section, bool $includeNested): void {
        if ($type === null || $section !== null || $includeNested) {
            return;
        }

        $sectionTypes = array_merge(...array_map(
            static fn (Section $model): array => $model->getEntryTypes(),
            Craft::$app->getEntries()->getAllSections(),
        ));

        if (array_any($sectionTypes, static fn (EntryType $entryType): bool => $entryType->handle === $type)) {
            return;
        }

        throw new ToolCallException(
            "Entry type '{$type}' belongs to no section, so no top-level entry has it: it is a Matrix block type,"
            . ' and blocks are out of scope unless includeNested is true. Pass includeNested: true to read blocks'
            . ' of that type.',
        );
    }

    /**
     * Refuse a section, type or status the install does not have.
     *
     * WHY: an unknown handle reached Craft as a filter nothing matches, so
     * "how many entries in section X" answered 0 where the truthful answer is
     * "there is no section X", and the agent went on to build on the zero.
     * The resolved models are discarded because a read filter only needs the
     * handle to be real; the query still travels by handle.
     */
    private function assertScope(?string $section, ?string $type, ?string $status): void {
        HandleResolver::entryType($type, HandleResolver::section($section));
        HandleResolver::entryStatus($status);
    }

    private function example(string $example, string $section): Entry {
        $byId = is_numeric($example) ? $this->lookup((int) $example, null, $section, null) : null;

        // Numeric-looking values that match no id fall back to a slug lookup.
        return $byId ?? $this->find(null, $example, $section, null);
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

        $resolution = $section === null
            ? Resolution::none()
            : $this->keys->resolve(Entry::class, ['section' => $section, 'slug' => $parent], $site);

        if ($resolution->ambiguous) {
            throw new ToolCallException(
                "Parent slug '{$parent}' matches more than one entry in section '{$section}'; pass the parent entry's id instead",
            );
        }

        return $resolution->id ?? throw new ToolCallException("Parent entry '{$parent}' not found");
    }

    private function authorId(): ?int {
        $user = Craft::$app->getUser()->getIdentity() ?? User::find()->admin()->one();

        return $user?->id;
    }
}
