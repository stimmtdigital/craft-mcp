<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\audit\LinkAudit;
use stimmt\craft\Mcp\audit\UnusedAssets;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\Window;

/**
 * Content audits Craft's own `content_model_audit` does not run: links that
 * lead nowhere live, and assets nothing refers to. Both are reads, scoped to
 * what the acting user may view, and bounded per call.
 */
final readonly class AuditTools {
    private const int MAX_SOURCES = 1000;

    private const int MAX_ASSETS = 500;

    public function __construct(
        private LinkAudit $links = new LinkAudit(),
        private UnusedAssets $assets = new UnusedAssets(),
    ) {
    }

    #[McpTool(
        name: 'stimmt_links_broken',
        title: 'Broken internal links',
        description: 'Entries whose content links to an entry, asset or category that is not live on the site the'
            . ' link is shown on. Reads relation fields (relations table) and the reference tags rich text and'
            . ' Link fields store ({entry:12@1:url}), in each entry and every nested entry beneath it. Reasons:'
            . ' deleted (in the trash), disabled (or archived), expired, pending, missing (no such element on that'
            . ' site), draft-only (the target is a draft). Pages through entries, not findings: limit entries are'
            . ' scanned per call (at most 1000, with up to 5000 nested entries, 8 levels deep, and 50000 relations between them;'
            . ' more is refused), and "total"/"hasMore" count entries, so ask again at offset + limit to scan the'
            . ' rest. Nested entries deeper than 8 levels are not followed. Lists only entries you may view; a target you may not view shows its id and reason, no title.'
            . ' "unchecked" counts tags that name a target by slug rather than id, which are not checked.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresEdition(Edition::Pro)]
    public function brokenLinks(
        #[Schema(description: 'Section handle. Omit to scan entries from every section.')]
        ?string $section = null,
        #[Schema(description: 'Site handle the links are checked for. Omit for the primary site.')]
        ?string $site = null,
        #[Schema(
            description: 'How many entries to scan, 1 to 1000.',
            minimum: Window::MIN_LIMIT,
            maximum: self::MAX_SOURCES,
        )]
        int $limit = 200,
        #[Schema(
            description: 'How many entries to skip before the first scanned one. 0 or greater.',
            minimum: Window::MIN_OFFSET,
        )]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        $this->assertAtMost($limit, self::MAX_SOURCES);
        $sectionModel = HandleResolver::section($section);
        $siteModel = SiteResolver::resolve($site) ?? Craft::$app->getSites()->getPrimarySite();

        // Section-less entries are nested ones; they are reached through
        // their owners, so the page holds sectioned entries only. Ordered by
        // id so a page boundary stays put between calls.
        $query = Entry::find()
            ->section($sectionModel ?? '*')
            ->siteId($siteModel->id)
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC])
            ->limit($limit)
            ->offset($offset);
        Authorization::scopeQuery($query);

        $result = $this->links->run($query, $siteModel);
        $total = (int) $query->count();
        $scanned = count($result['sources']);

        // The window is over entries, so whether more remain is a question
        // about entries scanned, not about the findings listed.
        return Budget::assert([
            ...Response::paginated('broken', $result['broken'], $total, $limit, $offset),
            'hasMore' => $offset + $scanned < $total,
            'site' => $siteModel->handle,
            'scanned' => $scanned,
            'unchecked' => $result['unchecked'],
        ]);
    }

    #[McpTool(
        name: 'stimmt_assets_unused',
        title: 'Unused assets',
        description: 'Assets nothing in Craft refers to, largest first. "Unused" means not referenced in Craft: no'
            . ' relation to it from any field, no {asset:ID} reference tag in any content, and not a user photo,'
            . ' counting canonical elements and drafts but not revisions or the trash. Templates, front-end code,'
            . ' other sites or emails may still hardcode its URL, so treat this as a list to review, never a list'
            . ' to delete: confirm with a person before removing anything. "totalBytes" sums every match, not only'
            . ' this page. Only volumes you may view are listed. Refused rather than answered partially when more'
            . ' than 20000 content rows carry asset tags, or the tags name more than 10000 distinct assets.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresEdition(Edition::Pro)]
    public function unusedAssets(
        #[Schema(description: 'Volume handle. Omit to list unused assets from every volume you may view.')]
        ?string $volume = null,
        #[Schema(description: 'Only assets created at least this many days ago. 0 (default) for any age.', minimum: 0)]
        int $olderThan = 0,
        #[Schema(
            description: 'How many assets to return, 1 to 500.',
            minimum: Window::MIN_LIMIT,
            maximum: self::MAX_ASSETS,
        )]
        int $limit = 100,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        $this->assertAtMost($limit, self::MAX_ASSETS);
        if ($olderThan < 0) {
            throw new ToolCallException("olderThan must be 0 or greater, got {$olderThan}.");
        }

        $volumeModel = HandleResolver::volume($volume);
        $query = $this->assets->query($volumeModel, $olderThan, $limit, $offset);
        Authorization::scopeQuery($query);

        /** @var list<Asset> $page */
        $page = $query->all();
        $assets = array_map(UnusedAssets::serialize(...), $page);

        return Budget::assert([
            ...Response::paginated('assets', $assets, (int) $query->count(), $limit, $offset),
            'volume' => $volumeModel?->handle,
            'totalBytes' => UnusedAssets::totalBytes($query),
        ]);
    }

    private function assertAtMost(int $limit, int $max): void {
        if ($limit > $max) {
            throw new ToolCallException("limit must be {$max} or lower, got {$limit}. Page with offset instead.");
        }
    }
}
