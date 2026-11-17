<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\DateTimeHelper;
use craft\models\Volume;
use DateInterval;
use DateTimeInterface;
use Mcp\Exception\ToolCallException;

/**
 * The assets nothing in Craft refers to.
 *
 * "Used" means any one of: the target of a `relations` row (any field, Link
 * fields included) from a counted source; named by id in a ref tag inside a
 * counted source's `elements_sites.content`; or a user's photo
 * (`users.photoId`, the one core column that points at an asset). Counted
 * sources are decided by CountedSources. Rebrand logos and icons live in
 * storage/rebrand as files, not assets, so they cannot make an asset used.
 *
 * Relations and photos are checked inside the asset query itself, so they
 * need no bound. The ref-tag half is a scan of content rows, bounded by
 * MAX_CONTENT_ROWS rows and MAX_TAGGED_ASSETS distinct ids; past either the
 * call is refused, because a partial scan would list used assets as unused.
 */
final class UnusedAssets {
    public const int MAX_CONTENT_ROWS = 20000;

    public const int MAX_TAGGED_ASSETS = 10000;

    private const int BATCH = 500;

    /**
     * The unused-asset query, windowed and ordered largest first. The caller
     * scopes it to the volumes the acting user may view.
     */
    public function query(?Volume $volume, int $olderThanDays, int $limit, int $offset): AssetQuery {
        $query = Asset::find()
            ->orderBy(['assets.size' => SORT_DESC, 'elements.id' => SORT_ASC])
            ->limit($limit)
            ->offset($offset)
            ->andWhere(['not exists', $this->relatedSubquery()])
            ->andWhere(['not exists', $this->photoSubquery()]);

        if ($volume !== null) {
            $query->volumeId($volume->id);
        }

        if ($olderThanDays > 0) {
            $cutoff = DateTimeHelper::now()->sub(new DateInterval("P{$olderThanDays}D"));
            $query->dateCreated('< ' . $cutoff->format(DateTimeInterface::ATOM));
        }

        $tagged = $this->taggedAssetIds();
        if ($tagged !== []) {
            $query->andWhere(['not', ['elements.id' => $tagged]]);
        }

        return $query;
    }

    /**
     * Sum of every matching asset's size, not just this page's.
     */
    public static function totalBytes(AssetQuery $query): int {
        // Craft's element query drops limit, offset and order for aggregates.
        return (int) $query->sum('[[assets.size]]');
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(Asset $asset): array {
        return [
            'id' => (int) $asset->id,
            'filename' => $asset->filename,
            'path' => $asset->getPath(),
            'volume' => $asset->getVolume()->handle,
            'size' => $asset->size,
            'dateCreated' => $asset->dateCreated?->format(DateTimeInterface::ATOM),
            'cpEditUrl' => $asset->getCpEditUrl(),
        ];
    }

    /**
     * A relation from a counted source to the asset row being considered.
     * Correlated on `elements`, the alias Craft's element query gives the
     * asset's own elements row.
     */
    private function relatedSubquery(): Query {
        return (new Query())
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['s' => Table::ELEMENTS], '[[s.id]] = [[r.sourceId]]')
            ->where('[[r.targetId]] = [[elements.id]]')
            ->andWhere(CountedSources::condition('s'));
    }

    private function photoSubquery(): Query {
        return (new Query())
            ->from(['u' => Table::USERS])
            ->where('[[u.photoId]] = [[elements.id]]');
    }

    /**
     * Every asset id a counted source names in a ref tag, read in id order a
     * batch at a time so memory holds one batch of content, never the table.
     *
     * @return list<int>
     */
    private function taggedAssetIds(): array {
        $references = new AssetReferences();
        $after = 0;
        $scanned = 0;

        do {
            [$after, $read] = $this->scanBatch($after, $references);
            $scanned += $read;
            $this->assertWithinBounds($scanned, $references);
        } while ($read === self::BATCH);

        return $references->ids();
    }

    /**
     * @return array{0: int, 1: int} the last row id read, and how many rows
     */
    private function scanBatch(int $after, AssetReferences $references): array {
        $rows = (new Query())
            ->select(['es.id', 'es.content'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where(['>', 'es.id', $after])
            ->andWhere(CountedSources::condition('e'))
            ->andWhere(ContentFilter::mentioning('es.content', [(string) Asset::refHandle()]))
            ->orderBy(['es.id' => SORT_ASC])
            ->limit(self::BATCH)
            ->all();

        foreach ($rows as $row) {
            $refs = ContentRefs::inJson(is_string($row['content']) ? $row['content'] : null);
            $references->addTags(array_map(static fn (FieldRef $ref): RefTag => $ref->tag, $refs));
            $after = (int) $row['id'];
        }

        return [$after, count($rows)];
    }

    private function assertWithinBounds(int $scanned, AssetReferences $references): void {
        if ($scanned > self::MAX_CONTENT_ROWS || $references->count() > self::MAX_TAGGED_ASSETS) {
            throw new ToolCallException(
                'More than ' . self::MAX_CONTENT_ROWS . ' content rows (or ' . self::MAX_TAGGED_ASSETS . ' distinct'
                . ' assets) are referenced by asset tags on this install, more than one call reads. This tool cannot'
                . ' prove an asset unused without reading them all, so it answers nothing rather than a list that'
                . ' may hold used assets.',
            );
        }
    }
}
