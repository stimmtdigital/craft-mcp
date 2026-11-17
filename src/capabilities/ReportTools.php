<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\mcp\attributes\RequiresAdmin;
use DateTimeImmutable;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\report\ContentChanges;
use stimmt\craft\Mcp\support\Budget;

/**
 * The figures for a periodic maintenance report on one install.
 *
 * Composes the health and error tools rather than re-implementing them, and
 * adds what neither covers: how much content changed, where, and by whom.
 */
final readonly class ReportTools {
    public const int MAX_DAYS = 90;

    /** The furthest back stimmt_errors_summary reads. */
    private const int MAX_ERROR_HOURS = 720;

    /** More saves than this in one period is not a report, it is an import. */
    private const int MAX_REVISIONS = 50000;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'stimmt_maintenance_report',
        title: 'Maintenance report',
        description: 'The figures for a maintenance report over the last N days, in one call: install health (problems only, with what to do), the most frequent errors, available and critical updates, the queue, and content changes per section and per editor. Read-only; summarise it for a person, do not paste it whole.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    #[RequiresEdition(Edition::Pro)]
    public function maintenanceReport(
        #[Schema(description: 'The period to report on, in days back from now. 1 to 90; default 30.', minimum: 1, maximum: self::MAX_DAYS)]
        int $days = 30,
    ): array {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new ToolCallException('days must be between 1 and ' . self::MAX_DAYS . '.');
        }

        $to = new DateTimeImmutable();
        $from = $to->modify("-{$days} days");
        $health = (new OperationsTools())->health();
        $problems = self::problems($health['checks']);
        // The log scan reaches back 30 days at most; a longer report says so
        // rather than failing, since the rest of it is still worth having.
        $errorHours = min($days * 24, self::MAX_ERROR_HOURS);
        $errors = (new OperationsTools())->errorsSummary(hours: $errorHours, limit: 5);

        return Budget::assert([
            'period' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM), 'days' => $days],
            'health' => ['summary' => $health['summary'], 'problems' => $problems],
            'errors' => [
                'windowDays' => intdiv($errorHours, 24),
                'total' => $errors['totalErrors'] ?? null,
                'complete' => $errors['complete'] ?? null,
                'top' => array_map(static fn (array $group): array => [
                    'kind' => $group['kind'],
                    'message' => $group['message'],
                    'count' => $group['count'],
                    'lastSeen' => $group['lastSeen'],
                ], $errors['groups'] ?? []),
            ],
            'content' => $this->contentChanges($from),
        ]);
    }

    /**
     * The checks that need someone: warnings and errors. Info lines (a
     * database version, updates available) belong in the full health call,
     * not in a summary of what to fix.
     *
     * @param list<array{level: string}> $checks
     * @return list<array{level: string}>
     */
    public static function problems(array $checks): array {
        return array_values(array_filter($checks, static fn (array $check): bool => in_array($check['level'], ['warning', 'error'], true)));
    }

    /**
     * @return array<string, mixed>
     */
    private function contentChanges(DateTimeImmutable $from): array {
        $rows = (new Query())
            ->select(['entries.sectionId', 'revisions.creatorId', 'revisions.num'])
            ->from(['elements' => Table::ELEMENTS])
            ->innerJoin(['revisions' => Table::REVISIONS], '[[revisions.id]] = [[elements.revisionId]]')
            ->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]')
            ->where(['>=', 'elements.dateCreated', Db::prepareDateForDb($from)])
            ->andWhere(['not', ['entries.sectionId' => null]])
            ->limit(self::MAX_REVISIONS + 1)
            ->all();

        if (count($rows) > self::MAX_REVISIONS) {
            throw new ToolCallException('More than ' . self::MAX_REVISIONS . ' entry saves in this period; ask for fewer days.');
        }

        $typed = array_map(static fn (array $row): array => [
            'sectionId' => $row['sectionId'] === null ? null : (int) $row['sectionId'],
            'creatorId' => $row['creatorId'] === null ? null : (int) $row['creatorId'],
            'num' => (int) $row['num'],
        ], $rows);

        return ContentChanges::aggregate($typed, $this->sectionHandles(), $this->usernames($typed));
    }

    /**
     * @return array<int, string>
     */
    private function sectionHandles(): array {
        $handles = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $handles[(int) $section->id] = (string) $section->handle;
        }

        return $handles;
    }

    /**
     * @param list<array{sectionId: int|null, creatorId: int|null, num: int}> $rows
     * @return array<int, string>
     */
    private function usernames(array $rows): array {
        $ids = array_values(array_unique(array_filter(array_column($rows, 'creatorId'))));
        if ($ids === []) {
            return [];
        }

        return array_map(strval(...), (new Query())->select(['username'])->from(Table::USERS)->where(['id' => $ids])->indexBy('id')->column());
    }
}
