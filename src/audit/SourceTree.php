<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use Mcp\Exception\ToolCallException;

/**
 * The scanned entries plus every nested entry beneath them.
 *
 * Links in a Matrix block live in the block's own content and relations, not
 * in its owner's, so an audit reading only the entries a caller paged through
 * would miss most links on a block-built site.
 *
 * Bounded twice: at most MAX_DEPTH levels of nesting, and at most MAX_NESTED
 * nested entries per call. Past the second the call is refused rather than
 * cut short, because a scan that silently skipped blocks would report fewer
 * broken links than there are.
 */
final class SourceTree {
    public const int MAX_NESTED = 5000;

    private const int MAX_DEPTH = 8;

    /** @var array<int, SourceNode> */
    private array $nodes = [];

    private int $roots = 0;

    /**
     * @param list<Entry> $entries
     * @return array<int, SourceNode> keyed by element id
     */
    public function build(array $entries): array {
        $this->nodes = [];
        foreach ($entries as $entry) {
            $this->nodes[(int) $entry->id] = new SourceNode((int) $entry->id, '', $entry->fieldLayoutId);
        }
        $this->roots = count($this->nodes);

        $parents = array_keys($this->nodes);
        for ($depth = 0; $parents !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $parents = $this->addLevel($parents);
        }

        return $this->nodes;
    }

    /**
     * Adds the canonical, live-in-the-database children of $parents and
     * returns their ids, the next level's parents.
     *
     * @param list<int> $parents
     * @return list<int>
     */
    private function addLevel(array $parents): array {
        $room = self::MAX_NESTED - (count($this->nodes) - $this->roots);
        $rows = (new Query())
            ->select(['e.id', 'e.primaryOwnerId', 'e.fieldId', 'el.fieldLayoutId'])
            ->from(['e' => Table::ENTRIES])
            ->innerJoin(['el' => Table::ELEMENTS], '[[el.id]] = [[e.id]]')
            ->where(['e.primaryOwnerId' => $parents, 'el.draftId' => null, 'el.revisionId' => null])
            ->andWhere(['el.dateDeleted' => null])
            ->limit($room + 1)
            ->all();

        if (count($rows) > $room) {
            throw new ToolCallException(
                'These entries hold more than ' . self::MAX_NESTED . ' nested entries between them, more than one'
                . ' call scans. Ask again with a lower limit, or narrow it to one section.',
            );
        }

        return array_map($this->addRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addRow(array $row): int {
        $id = (int) $row['id'];
        $owner = $this->nodes[(int) $row['primaryOwnerId']];
        $handle = Craft::$app->getFields()->getFieldById((int) $row['fieldId'])->handle ?? "field{$row['fieldId']}";
        $layoutId = $row['fieldLayoutId'] === null ? null : (int) $row['fieldLayoutId'];

        $this->nodes[$id] = new SourceNode($owner->rootId, $owner->field($handle . '.'), $layoutId);

        return $id;
    }
}
