<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\models\Site;

/**
 * Finds the links in one page of entries that lead nowhere live.
 *
 * The caller hands over an entry query already windowed and scoped to what
 * the acting user may view; this reads the page, everything nested beneath
 * it, and the targets of every link found there.
 */
final readonly class LinkAudit {
    public function __construct(
        private SourceTree $tree = new SourceTree(),
        private LinkCollector $collector = new LinkCollector(),
        private TargetInspector $inspector = new TargetInspector(),
    ) {
    }

    /**
     * @return array{sources: list<Entry>, broken: list<array<string, mixed>>, unchecked: int}
     */
    public function run(EntryQuery $page, Site $site): array {
        /** @var list<Entry> $sources */
        $sources = $page->all();
        $nodes = $this->tree->build($sources);
        $findings = $this->inspector->broken($this->collector->collect($nodes, (int) $site->id));

        $byId = [];
        foreach ($sources as $source) {
            $byId[(int) $source->id] = $source;
        }

        $broken = array_map(
            static fn (array $finding): array => self::serialize(
                $finding,
                $byId[$finding['candidate']->sourceId],
                $site,
            ),
            $findings,
        );
        usort($broken, static fn (array $a, array $b): int => self::order($a) <=> self::order($b));

        return ['sources' => $sources, 'broken' => $broken, 'unchecked' => $this->collector->unchecked()];
    }

    /**
     * Grouped by entry, then by field, so one entry's findings read together.
     *
     * @param array<string, mixed> $finding
     * @return array{0: mixed, 1: mixed}
     */
    private static function order(array $finding): array {
        return [$finding['source']['id'], $finding['field']];
    }

    /**
     * The target's title travels only when the acting user may view it; an
     * id and a reason say what to fix without revealing what it was.
     *
     * @param array{candidate: LinkCandidate, reason: BrokenReason, title: ?string} $finding
     * @return array<string, mixed>
     */
    private static function serialize(array $finding, Entry $source, Site $site): array {
        $candidate = $finding['candidate'];
        $target = ['type' => $candidate->type::refHandle(), 'id' => $candidate->targetId];
        if ($finding['title'] !== null) {
            $target['title'] = $finding['title'];
        }

        return [
            'source' => [
                'id' => (int) $source->id,
                'title' => $source->title,
                'section' => $source->getSection()?->handle,
                'site' => $site->handle,
                'status' => $source->getStatus(),
                'cpEditUrl' => $source->getCpEditUrl(),
            ],
            'field' => $candidate->field,
            'target' => [...$target, 'reason' => $finding['reason']->value],
        ];
    }
}
