<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use DateTimeImmutable;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use nystudio107\retour\Retour;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integrations\Lookback;
use stimmt\craft\Mcp\integrations\PluginGate;
use stimmt\craft\Mcp\integrations\retour\EntrySuggestions;
use stimmt\craft\Mcp\integrations\retour\NotFoundStats;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\Window;

/**
 * Retour reads: which missing URLs visitors keep hitting, and what they
 * probably meant.
 *
 * Only registered when Retour 5 (the Craft 5 line) is installed and enabled.
 * Not admin-only: a 404 URL is public by nature, and the one field that can
 * carry a visitor's data, the referrer, is cut to its path.
 */
final readonly class RetourTools {
    /** The Craft 5 line, whose stats and redirects tables this reads. */
    private const string MIN_VERSION = '5.0.0';

    /** Each suggestion costs one bounded entry query, so only the top rows of a page get one. */
    private const int MAX_SUGGESTIONS = 25;

    private NotFoundStats $stats;

    private EntrySuggestions $suggestions;

    public function __construct(?NotFoundStats $stats = null, ?EntrySuggestions $suggestions = null) {
        $this->stats = $stats ?? new NotFoundStats();
        $this->suggestions = $suggestions ?? new EntrySuggestions();
    }

    public static function isAvailable(): bool {
        return class_exists(Retour::class) && PluginGate::enabledAtLeast('retour', self::MIN_VERSION);
    }

    #[McpTool(
        name: 'stimmt_redirects_404s',
        title: 'Most frequent 404s',
        description: "Retour's 404 statistics, most-hit first: the missing URL, its hit count, last hit, referrer"
            . ' (path only), whether a redirect handles it, and a suggested existing entry by slug similarity.'
            . ' The suggestion is a guess to check, not a redirect target to apply blindly.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresEdition(Edition::Pro)]
    public function notFound(
        #[Schema(description: 'Site handle. Omit for 404s on every site.')]
        ?string $site = null,
        #[Schema(description: 'Only URLs last hit in the past N days, 1 to 3650. Hit counts are lifetime totals.', minimum: 1, maximum: Lookback::MAX_DAYS)]
        int $days = 30,
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
        #[Schema(description: 'Skip URLs a redirect already handles. Default true; pass false to audit those as well.')]
        bool $excludeHandled = true,
    ): array {
        Window::assert($limit, $offset);
        $siteModel = SiteResolver::resolve($site);
        $since = Lookback::since($days, new DateTimeImmutable());

        $query = $this->stats->query($siteModel?->id, $since, $excludeHandled);
        $total = (int) (clone $query)->count();
        $rows = $this->shape($query->limit($limit)->offset($offset)->all(), $siteModel?->id);

        return Budget::assert([
            ...Response::paginated('urls', $rows, $total, $limit, $offset),
            'since' => $since->format(DATE_ATOM),
            'notes' => [
                'hits is the lifetime count Retour keeps per URL; days filters on the most recent hit.',
                'handled means Retour redirected the last hit, or an enabled exact-match redirect now exists'
                . ' for the path. Regex redirects are not evaluated.',
                'Suggestions are computed for the first ' . self::MAX_SUGGESTIONS . ' rows of a page only.',
            ],
        ]);
    }

    /**
     * The tool's rows, with a suggestion on the first MAX_SUGGESTIONS of them.
     *
     * WHY the suggestion searches the 404's own site: a slug on another site
     * is a page in another language or brand, not the page the visitor
     * wanted. Retour stores no site on rows from before its multi-site
     * migration, and those fall back to the requested or primary site.
     *
     * @param list<array<string, mixed>> $stats retour_stats rows
     * @return list<array<string, mixed>>
     */
    private function shape(array $stats, ?int $siteId): array {
        $fallbackSiteId = $siteId ?? (int) Craft::$app->getSites()->getPrimarySite()->id;
        $rows = [];

        foreach ($stats as $index => $stat) {
            $row = NotFoundStats::row($stat);
            $rowSiteId = ($stat['siteId'] ?? null) === null ? $fallbackSiteId : (int) $stat['siteId'];
            $suggestion = $index < self::MAX_SUGGESTIONS ? $this->suggestions->forUrl($row['url'], $rowSiteId) : null;

            $rows[] = $suggestion === null ? $row : [...$row, 'suggestion' => $suggestion];
        }

        return $rows;
    }
}
