<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\base\ElementInterface;
use stimmt\craft\Mcp\support\Authorization;

/**
 * Looks up link targets, one query per element type and site, and reports
 * each broken one.
 *
 * Bounded by the candidates the collector found, which the scanned entries
 * bound in turn.
 */
final class TargetInspector {
    /** @var array<string, array<int, ElementInterface>> found targets, by lookup key then id */
    private array $found = [];

    /** @var array<string, array<int, true>> targets the acting user may view, by lookup key then id */
    private array $viewable = [];

    /**
     * @param array<string, LinkCandidate> $candidates
     * @return list<array{candidate: LinkCandidate, reason: BrokenReason, title: ?string}>
     */
    public function broken(array $candidates): array {
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate->lookupKey()][] = $candidate;
        }

        array_map($this->load(...), $groups);

        return array_values(array_filter(array_map($this->finding(...), array_values($candidates))));
    }

    /**
     * @param non-empty-list<LinkCandidate> $group candidates sharing one type and site
     */
    private function load(array $group): void {
        $first = $group[0];
        $key = $first->lookupKey();
        $this->found[$key] = [];
        $this->viewable[$key] = [];

        // A tag naming a site the install does not have resolves nowhere.
        if ($first->siteId === null) {
            return;
        }

        // Every state a target can be in, so a trashed, disabled or draft
        // target is found and named rather than reported as missing.
        $query = $first->type::find()
            ->id(array_values(array_unique(array_map(static fn (LinkCandidate $c): int => $c->targetId, $group))))
            ->siteId($first->siteId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->trashed(null);

        // Titles only for targets the acting user may view: the same view
        // scope as any list tool, applied to a copy of the lookup.
        $visible = clone $query;
        Authorization::scopeQuery($visible);
        $this->viewable[$key] = array_fill_keys(array_map(intval(...), $visible->ids()), true);

        foreach ($query->all() as $element) {
            $this->found[$key][(int) $element->id] = $element;
        }
    }

    /**
     * @return array{candidate: LinkCandidate, reason: BrokenReason, title: ?string}|null
     */
    private function finding(LinkCandidate $candidate): ?array {
        $element = $this->found[$candidate->lookupKey()][$candidate->targetId] ?? null;
        $reason = LinkHealth::reason($element === null ? null : $this->state($element));
        if ($reason === null) {
            return null;
        }

        $visible = isset($this->viewable[$candidate->lookupKey()][$candidate->targetId]);

        return [
            'candidate' => $candidate,
            'reason' => $reason,
            'title' => $visible && $element !== null ? (string) $element->title : null,
        ];
    }

    private function state(ElementInterface $element): TargetState {
        return new TargetState($element->trashed, $element->getIsDraft(), $element->getStatus());
    }
}
