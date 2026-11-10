<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\elements\query\Buckets;
use stimmt\craft\Mcp\elements\query\Filters;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\SiteResolver;

/**
 * Entry reads that Craft's own MCP server does not have: grouped counts,
 * natural keys to element ids, and preview links.
 *
 * Craft's `elements_*` tools address elements by id. `stimmt_refs_resolve`
 * turns the keys a person thinks in (a section and a slug, a volume and a
 * filename) into those ids, so the two sets of tools work together instead
 * of this plugin keeping a second entry CRUD next to Craft's.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class EntryTools {
    private const string ANY_SECTION = '*';

    /** Natural-key types, by the name a caller passes. */
    private const array REF_TYPES = [
        'entry' => Entry::class,
        'asset' => Asset::class,
        'category' => Category::class,
        'tag' => Tag::class,
        'user' => User::class,
        'globalSet' => GlobalSet::class,
    ];

    private Keys $keys;

    private Filters $filters;

    public function __construct(?Keys $keys = null, ?Filters $filters = null) {
        $this->keys = $keys ?? new Keys();
        $this->filters = $filters ?? new Filters();
    }

    /**
     * @param array<string, mixed>|null $filters
     * @param array<string, mixed>|null $relatedTo
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stimmt_entries_count',
        title: 'Count and group entries',
        description: 'Count entries, optionally grouped: by attribute (status, type, section, site, author), by date bucket ("month:dateUpdated", day|week|month|year with dateCreated|dateUpdated|postDate), or by a field handle (relation fields bucket by related title, empty values under "(empty)"). Nested Matrix blocks are left out unless includeNested. Counts include EVERY status by default; pass status to narrow. One call answers "how many per X" without listing anything.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    public function countEntries(
        #[Schema(description: 'Section handle to count within. Omit to count across every section, which is every top-level entry the install has.')]
        ?string $section = null,
        #[Schema(description: 'Count nested Matrix blocks alongside top-level entries. Left out by default.')]
        bool $includeNested = false,
        #[Schema(description: 'Entry type handle.')]
        ?string $type = null,
        #[Schema(description: 'Entry status: live, pending, expired, disabled, or "any". Omitted counts every status.')]
        ?string $status = null,
        #[Schema(description: 'Site handle. Omit for the primary site.')]
        ?string $site = null,
        #[Schema(description: 'Craft search query.')]
        ?string $search = null,
        #[Schema(type: 'object', description: 'Field-value filters: {fieldHandle: value}. Values are scalars, ":empty:", ":notempty:", or a natural key object for relation fields.', additionalProperties: true)]
        ?array $filters = null,
        #[Schema(type: 'object', description: 'Only entries related to this element, as a natural key: {"section","slug"}, {"volume","filename"}, {"group","slug"}, or {"username"}.', additionalProperties: true)]
        ?array $relatedTo = null,
        #[Schema(description: 'Author username or email address.')]
        ?string $author = null,
        #[Schema(description: 'Lower bound on dateUpdated, inclusive. Any date Craft parses, such as "2026-01-31".')]
        ?string $updatedAfter = null,
        #[Schema(description: 'Upper bound on dateUpdated, exclusive.')]
        ?string $updatedBefore = null,
        #[Schema(description: 'Lower bound on dateCreated, inclusive.')]
        ?string $createdAfter = null,
        #[Schema(description: 'Upper bound on dateCreated, exclusive.')]
        ?string $createdBefore = null,
        #[Schema(description: 'What to bucket the count by: an attribute (status, type, section, site, author), a date bucket such as "month:dateUpdated", or a field handle. Omit for a plain total.')]
        ?string $groupBy = null,
    ): array {
        SiteResolver::resolve($site);
        HandleResolver::entryType($type, HandleResolver::section($section));
        HandleResolver::entryStatus($status);
        $this->assertTypeInScope($type, $section, $includeNested);

        $query = Entry::find();
        $this->scopeToSections($query, $section, $includeNested);
        $query->status($status === null || $status === 'any' ? null : $status);

        foreach (['type' => $type, 'site' => $site, 'search' => $search] as $method => $value) {
            if ($value !== null) {
                $query->$method($value);
            }
        }

        $this->filters->apply($query, $filters, $relatedTo, $author, $updatedAfter, $updatedBefore, $createdAfter, $createdBefore, $site);

        // Only when the caller has not pinned a site themselves: having asked
        // about one site, a single bucket for it is the honest answer.
        if ($site === null && Buckets::spansSites($groupBy)) {
            $query->siteId('*');
        }

        Authorization::scopeQuery($query);

        $result = (new Buckets())->collect($query, $groupBy);
        $result['groupBy'] = $groupBy;

        return $result;
    }

    /**
     * @param array<string, string> $key
     * @return array{type: string, key: array<string, string>, id: int}
     */
    #[McpTool(
        name: 'stimmt_refs_resolve',
        title: 'Natural key to element id',
        description: 'Turn a natural key into the element id Craft\'s elements_* tools take. Keys per type: entry {"section","slug"}, asset {"volume","filename"} (plus "path" for a file in a folder), category and tag {"group","slug"}, user {"username"}, globalSet {"handle"}. Refuses a key that matches nothing, or several elements.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    public function resolveRef(
        #[Schema(description: 'Element type: entry, asset, category, tag, user or globalSet.', enum: ['entry', 'asset', 'category', 'tag', 'user', 'globalSet'])]
        string $type,
        #[Schema(type: 'object', description: 'The natural key, e.g. {"section": "news", "slug": "launch"}.', additionalProperties: true)]
        array $key,
        #[Schema(description: 'Site handle, for types whose slugs differ per site. Omit for the primary site.')]
        ?string $site = null,
    ): array {
        $elementType = self::REF_TYPES[$type] ?? throw new ToolCallException(
            "Unknown type '{$type}'. Use one of: " . implode(', ', array_keys(self::REF_TYPES)) . '.',
        );
        SiteResolver::resolve($site);

        $resolution = $this->keys->resolve($elementType, $key, $site);
        if ($resolution->id === null) {
            $shape = implode(', ', $this->keys->keyShape($elementType) ?? []);

            $article = in_array($type[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A';

            throw new ToolCallException($resolution->explain($type) . ". {$article} {$type} key has: {$shape}.");
        }

        $element = Craft::$app->getElements()->getElementById($resolution->id, $elementType, $site === null ? null : SiteResolver::resolve($site));
        if ($element !== null) {
            Authorization::assertCanView($element);
        }

        return ['type' => $type, 'key' => $key, 'id' => $resolution->id];
    }

    /**
     * @return array{id: int, previewUrl: string}
     */
    #[McpTool(
        name: 'stimmt_entries_preview_url',
        title: 'Preview link for an entry or draft',
        description: 'A link that renders an entry or draft as saved, without logging in, for a person to review before publishing. Pass the draft\'s element id to preview unpublished changes. Refuses entries in sections without URLs. The link expires (previewTokenDuration, or Craft\'s defaultTokenDuration).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function previewUrl(
        #[Schema(description: 'Entry or draft element id.')]
        int $id,
        #[Schema(description: 'Site handle. Omit for the primary site.')]
        ?string $site = null,
    ): array {
        $entry = Entry::find()->id($id)->drafts(null)->provisionalDrafts(null)->status(null)
            ->site($site ?? '*')->preferSites(array_filter([$site]))->unique()->one()
            ?? throw new ToolCallException("No entry or draft with id {$id}.");
        Authorization::assertCanView($entry);

        $url = ElementModule::preview()->for($entry)
            ?? throw new ToolCallException("Entry {$id} has no URL to preview: its section has no URLs on this site.");

        return ['id' => $id, 'previewUrl' => $url];
    }

    /**
     * WHY a section-less count is not an unfiltered query: in Craft 5 a
     * Matrix block IS an entry, so Entry::find() hands back blocks beside the
     * content that was asked for. '*' means "in any section", and a block is
     * in none.
     */
    private function scopeToSections(EntryQuery $query, ?string $section, bool $includeNested): void {
        if ($section !== null) {
            $query->section($section);

            return;
        }

        if (!$includeNested) {
            $query->section(self::ANY_SECTION);
        }
    }

    /**
     * Refuse an entry type no section has while blocks are out of scope,
     * instead of answering a confident zero about a Matrix block type.
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
            "Entry type '{$type}' belongs to no section, so no top-level entry has it: it is a Matrix block type."
            . ' Pass includeNested: true to count blocks of that type.',
        );
    }
}
