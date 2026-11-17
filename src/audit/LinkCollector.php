<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\BaseRelationField;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\models\Site;
use Mcp\Exception\ToolCallException;

/**
 * Every link in a set of scanned elements, from both places Craft keeps them.
 *
 * Relation fields (Entries, Assets, Categories) write `relations` rows; rich
 * text and Link fields write ref tags into `elements_sites.content`. A Link
 * field also writes a relation row, but only the tag carries the site it
 * links to, so relation rows are read for relation fields alone and Link
 * fields through their tags.
 *
 * Bounded by the scanned element ids, and by MAX_RELATIONS relation rows per
 * call, past which the call is refused rather than silently cut short.
 */
final class LinkCollector {
    public const int MAX_RELATIONS = 50000;

    /** The element types whose targets this audit checks. */
    private const array CHECKED = [Entry::class, Asset::class, Category::class];

    /** Ref tags that name something this audit cannot check (a slug path, `{entry:news/launch}`). */
    private int $unchecked = 0;

    /**
     * @param array<int, SourceNode> $nodes keyed by element id
     * @return array<string, LinkCandidate> keyed by LinkCandidate::key()
     */
    public function collect(array $nodes, int $siteId): array {
        $this->unchecked = 0;
        $candidates = [...$this->fromRelations($nodes, $siteId), ...$this->fromContent($nodes, $siteId)];

        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[$candidate->key()] ??= $candidate;
        }

        return $unique;
    }

    /** How many tags the last collect() could not check, so a clean result is not read as "all checked". */
    public function unchecked(): int {
        return $this->unchecked;
    }

    /**
     * @param array<int, SourceNode> $nodes
     * @return list<LinkCandidate>
     */
    private function fromRelations(array $nodes, int $siteId): array {
        $rows = (new Query())
            ->select(['r.sourceId', 'r.fieldId', 'r.targetId', 't.type'])
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['t' => Table::ELEMENTS], '[[t.id]] = [[r.targetId]]')
            ->where(['r.sourceId' => array_keys($nodes)])
            // A non-localized relation field stores one set of rows for every
            // site, with no source site.
            ->andWhere(['or', ['r.sourceSiteId' => null], ['r.sourceSiteId' => $siteId]])
            ->limit(self::MAX_RELATIONS + 1)
            ->all();

        if (count($rows) > self::MAX_RELATIONS) {
            throw new ToolCallException(
                'These entries hold more than ' . self::MAX_RELATIONS . ' relations between them, more than one call'
                . ' checks. Ask again with a lower limit.',
            );
        }

        return array_values(array_filter(array_map(
            fn (array $row): ?LinkCandidate => $this->fromRelation($row, $nodes, $siteId),
            $rows,
        )));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, SourceNode> $nodes
     */
    private function fromRelation(array $row, array $nodes, int $siteId): ?LinkCandidate {
        $field = Craft::$app->getFields()->getFieldById((int) $row['fieldId']);
        $type = (string) $row['type'];
        if (!$field instanceof BaseRelationField || !in_array($type, self::CHECKED, true)) {
            return null;
        }

        return new LinkCandidate(
            $nodes[(int) $row['sourceId']]->rootId,
            $nodes[(int) $row['sourceId']]->field((string) $field->handle),
            $type,
            (int) $row['targetId'],
            $this->relationSite($field, $siteId),
        );
    }

    /**
     * Mirrors BaseRelationField::targetSiteId(), which is protected: a field
     * pinned to one site resolves its targets there; otherwise on the
     * source's own site.
     */
    private function relationSite(BaseRelationField $field, int $siteId): int {
        if (!$field->targetSiteId || !Craft::$app->getIsMultiSite()) {
            return $siteId;
        }

        return $this->siteByUid($field->targetSiteId)->id ?? $siteId;
    }

    /**
     * @param array<int, SourceNode> $nodes
     * @return list<LinkCandidate>
     */
    private function fromContent(array $nodes, int $siteId): array {
        $rows = (new Query())
            ->select(['es.elementId', 'es.content'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->where(['es.elementId' => array_keys($nodes), 'es.siteId' => $siteId])
            ->andWhere(ContentFilter::mentioning('es.content', $this->refHandles()))
            ->all();

        $candidates = [];
        foreach ($rows as $row) {
            $node = $nodes[(int) $row['elementId']];
            $refs = ContentRefs::inJson(is_string($row['content']) ? $row['content'] : null);
            $candidates = [...$candidates, ...$this->fromRefs($refs, $node, $siteId)];
        }

        return $candidates;
    }

    /**
     * @param list<FieldRef> $refs
     * @return list<LinkCandidate>
     */
    private function fromRefs(array $refs, SourceNode $node, int $siteId): array {
        $layout = $node->fieldLayoutId === null ? null : Craft::$app->getFields()->getLayoutById($node->fieldLayoutId);

        return array_values(array_filter(array_map(
            function (FieldRef $ref) use ($layout, $node, $siteId): ?LinkCandidate {
                $element = $layout?->getElementByUid($ref->layoutUid);
                $handle = $element instanceof CustomField ? $element->attribute() : $ref->layoutUid;

                return $this->fromTag($ref->tag, $node->rootId, $node->field($handle), $siteId);
            },
            $refs,
        )));
    }

    private function fromTag(RefTag $tag, int $sourceId, string $field, int $siteId): ?LinkCandidate {
        $type = Craft::$app->getElements()->getElementTypeByRefHandle($tag->handle);
        if ($type === null || !in_array($type, self::CHECKED, true)) {
            return null;
        }

        $id = $tag->id();
        if ($id === null) {
            $this->unchecked++;

            return null;
        }

        $targetSite = $tag->site === null ? $siteId : $this->siteId($tag->site);

        return new LinkCandidate($sourceId, $field, $type, $id, $targetSite);
    }

    /**
     * A tag's site part, resolved the way Elements::parseRefs() does: an id,
     * a UUID or a handle. Null for a site the install does not have.
     */
    private function siteId(string $site): ?int {
        $sites = Craft::$app->getSites();
        if (is_numeric($site)) {
            return $sites->getSiteById((int) $site, true)?->id;
        }

        $model = StringHelper::isUUID($site) ? $this->siteByUid($site) : $sites->getSiteByHandle($site, true);

        return $model?->id;
    }

    /**
     * Sites::getSiteByUid() throws for an unknown UUID; a tag or field naming
     * a site that has since been deleted is a finding here, not a failure.
     */
    private function siteByUid(string $uid): ?Site {
        return ArrayHelper::firstWhere(Craft::$app->getSites()->getAllSites(true), 'uid', $uid);
    }

    /**
     * The short handles of the checked types, for the database prefilter.
     * Categories may have been entrified, in which case `{category:` tags
     * resolve to entries; the type is decided per tag, after the prefilter.
     *
     * @return list<string>
     */
    private function refHandles(): array {
        return array_values(array_filter(array_map(
            static fn (string $type): ?string => $type::refHandle(),
            self::CHECKED,
        )));
    }
}
