<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use nystudio107\seomatic\Seomatic;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integrations\ErrorText;
use stimmt\craft\Mcp\integrations\PluginGate;
use stimmt\craft\Mcp\integrations\seomatic\EffectiveMeta;
use stimmt\craft\Mcp\integrations\seomatic\SeoRules;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\Window;
use Throwable;

/**
 * SEOmatic reads: which live entries would render with missing or weak SEO
 * metadata, judged on the values SEOmatic itself resolves for each entry.
 *
 * Only registered when SEOmatic 5 (the Craft 5 line) is installed and enabled.
 */
final readonly class SeomaticTools {
    /** The Craft 5 line, whose meta container API EffectiveMeta calls. */
    private const string MIN_VERSION = '5.0.0';

    /**
     * Each scanned entry runs SEOmatic's full meta resolution, Twig included,
     * so the page an agent may ask for is bounded below what a request can
     * afford rather than by the response size alone.
     */
    private const int MAX_SCAN = 50;

    private EffectiveMeta $meta;

    public function __construct(?EffectiveMeta $meta = null) {
        $this->meta = $meta ?? new EffectiveMeta();
    }

    public static function isAvailable(): bool {
        return class_exists(Seomatic::class) && PluginGate::enabledAtLeast('seomatic', self::MIN_VERSION);
    }

    #[McpTool(
        name: 'stimmt_seo_gaps',
        title: 'SEO gaps',
        description: 'Scan live entries for SEO metadata as SEOmatic resolves it (global, section and SEO Settings'
            . ' field merged, templates parsed): missing description, description or title over the SEOmatic'
            . ' max length, missing og:image, and noindex on a live entry (a flag, not an error). Only entries with'
            . ' issues are returned; page through with offset, which counts entries scanned.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresEdition(Edition::Pro)]
    public function seoGaps(
        #[Schema(description: 'Section handle. Omit to scan entries from every section that has URLs.')]
        ?string $section = null,
        #[Schema(description: "Site handle. Omit for Craft's primary site.")]
        ?string $site = null,
        #[Schema(
            description: 'How many entries to scan (not how many to return). 1 to ' . self::MAX_SCAN . '.',
            minimum: Window::MIN_LIMIT,
            maximum: self::MAX_SCAN,
        )]
        int $limit = 25,
        #[Schema(description: 'How many entries to skip before scanning. 0 or greater.', minimum: Window::MIN_OFFSET)]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        $this->assertScanLimit($limit);

        // Ordered by id, so a page boundary does not move while entries change.
        $query = Entry::find()->uri(':notempty:')->orderBy(['elements.id' => SORT_ASC])->limit($limit)->offset($offset);
        $sectionModel = HandleResolver::section($section);
        if ($sectionModel !== null) {
            $query->sectionId($sectionModel->id);
        }
        $siteModel = SiteResolver::resolve($site);
        if ($siteModel !== null) {
            $query->siteId($siteModel->id);
        }
        Authorization::scopeQuery($query);

        $entries = $query->all();
        $rows = $this->scan($entries, $this->meta->rules());
        $total = (int) $query->count();

        return Budget::assert([
            // hasMore is restated: the paged unit is entries scanned, while
            // the list holds only the entries that had issues.
            ...Response::paginated('entries', $rows, $total, $limit, $offset),
            'hasMore' => $offset + count($entries) < $total,
            'scanned' => count($entries),
            'basis' => EffectiveMeta::BASIS,
            'maxTitleLength' => Seomatic::$settings->maxTitleLength,
            'maxDescriptionLength' => Seomatic::$settings->maxDescriptionLength,
            'notes' => [
                'Values are SEOmatic metaGlobalVars after parsing, before render-time truncation.',
                'title_too_long measures the SEO title without the site name SEOmatic appends.',
            ],
        ]);
    }

    private function assertScanLimit(int $limit): void {
        if ($limit > self::MAX_SCAN) {
            throw new ToolCallException(
                'limit must be ' . self::MAX_SCAN . " or less, got {$limit}: every entry is resolved through"
                . ' SEOmatic. Page through with offset instead.',
            );
        }
    }

    /**
     * @param list<Entry> $entries
     * @return list<array{id: int, title: ?string, section: ?string, url: ?string, issues: list<array<string, string>>}>
     */
    private function scan(array $entries, SeoRules $rules): array {
        $rows = [];
        foreach ($entries as $entry) {
            $issues = $this->issuesOf($entry, $rules);
            if ($issues === []) {
                continue;
            }

            $rows[] = [
                'id' => (int) $entry->id,
                'title' => $entry->title,
                'section' => $entry->getSection()?->handle,
                'url' => $entry->getUrl(),
                'issues' => $issues,
            ];
        }

        return $rows;
    }

    /**
     * WHY a failure is reported on the entry rather than thrown: one entry
     * whose templates SEOmatic cannot resolve says nothing about the rest of
     * the page, and it is itself a finding.
     *
     * @return list<array<string, string>>
     */
    private function issuesOf(Entry $entry, SeoRules $rules): array {
        try {
            return $rules->issues($this->meta->of($entry), $entry->getStatus() === Entry::STATUS_LIVE);
        } catch (Throwable $error) {
            return [[
                'issue' => 'meta_unresolved',
                'detail' => 'SEOmatic could not resolve this entry: ' . ErrorText::clean($error->getMessage()),
                'severity' => 'error',
            ]];
        }
    }
}
