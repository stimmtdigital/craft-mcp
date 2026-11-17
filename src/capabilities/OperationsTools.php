<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\mcp\attributes\RequiresAdmin;
use craft\queue\Queue;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\logging\Parser;
use stimmt\craft\Mcp\operations\ErrorScan;
use stimmt\craft\Mcp\operations\FailedJob;
use stimmt\craft\Mcp\operations\HealthProbe;
use stimmt\craft\Mcp\operations\HealthReport;
use stimmt\craft\Mcp\operations\QueueTable;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\Window;

/**
 * Operations tools: whether an install is healthy, what keeps failing in its
 * logs, and which queue jobs died.
 *
 * Each one only reads. The decisions (thresholds, grouping, ordering) live in
 * src/operations as plain classes; these methods gather Craft's data and hand
 * it over, so the decisions are tested without booting Craft.
 */
final class OperationsTools {
    public const int MIN_HOURS = 1;

    /** Thirty days: Craft keeps rotated logs per day, and further back than that they are rarely still on disk. */
    public const int MAX_HOURS = 720;

    #[McpTool(
        name: 'stimmt_health',
        title: 'Install health',
        description: 'One call that answers "is this install OK, and if not, what to do": the MCP setup, environment '
            . '(dev mode in production, admin changes, PHP, database), pending migrations and cached update info, '
            . 'project config (pending YAML, schema mismatches), the queue (failed and stuck jobs), storage (writable, '
            . 'free space) and errors logged in the last 24 hours. Checks come errors first; each problem carries a fix. '
            . 'Never contacts the update server and changes nothing.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function health(): array {
        return HealthReport::build(HealthProbe::run());
    }

    #[McpTool(
        name: 'stimmt_errors_summary',
        title: 'Recurring errors',
        description: 'Errors from Craft\'s logs over the last N hours, grouped by exception class, message (with ids, '
            . 'numbers and quoted values masked) and the first frame in project code. Each group has a count, first and '
            . 'last occurrence, the log sources it appeared in, and one example with its top stack frames. Most frequent '
            . 'first. Masking only decides the grouping: message and example are the raw log text, so they can contain'
            . ' what the log holds. Use stimmt_logs_read for the raw entries.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function errorsSummary(
        #[Schema(description: 'How many hours back to look, 1 to 720.', minimum: self::MIN_HOURS, maximum: self::MAX_HOURS)]
        int $hours = 24,
        #[Schema(description: 'Which log to read: web, console, queue, or a plugin name (a log file name prefix). Omit to read across every log file.')]
        ?string $source = null,
        #[Schema(description: 'How many groups to return. 1 or greater.', minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        ?RequestContext $context = null,
    ): array {
        Window::assert($limit);
        $this->assertHours($hours);

        $gateway = $context?->getClientGateway();
        $scan = ErrorScan::run(
            Craft::$app->getPath()->getLogPath(false),
            Craft::$app->getTimeZone(),
            $hours,
            $source,
            static function (string $file, int $position, int $total) use ($gateway): void {
                $gateway?->progress($position, $total, 'Scanning ' . basename($file));
            },
        );
        $groups = $scan->groups->groups();

        return Budget::assert(Response::capped('groups', array_slice($groups, 0, $limit), $limit, count($groups), [
            'hours' => $hours,
            'totalErrors' => count($scan->groups->inWindow()),
            'scannedFiles' => $scan->files,
            // False when the entry cap filled before the window's start was
            // reached: then every count is a floor, not a total.
            'complete' => $scan->complete(),
            'note' => 'Reads at most ' . ErrorScan::MAX_ENTRIES . ' error entries, and each log file back at most '
                . Parser::scanDepth() . '.',
        ]));
    }

    #[McpTool(
        name: 'stimmt_queue_failures',
        title: 'Failed queue jobs',
        description: 'Failed jobs in Craft\'s database queue, most recently failed first, with the job class, '
            . 'description, attempt count and the error it failed with. The job payload is never included. '
            . 'Retry with Craft\'s queue_retry_all, then queue_run.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function queueFailures(
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        $table = new QueueTable($this->databaseQueue());

        $jobs = array_map(
            static fn (array $row): array => FailedJob::present($row, $table->jobClass((int) $row['id'])),
            $table->failedPage($limit, $offset),
        );

        return Budget::assert([
            ...Response::paginated('jobs', $jobs, $table->failed(), $limit, $offset),
            'hint' => 'Fix the cause first, then retry every failed job with Craft\'s queue_retry_all and run them '
                . 'with queue_run (or let the queue worker pick them up).',
        ]);
    }

    private function databaseQueue(): Queue {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            throw new ToolCallException(
                'This install\'s queue driver (' . $queue::class . ') cannot be inspected: only Craft\'s database '
                . 'queue keeps failed jobs where they can be read. Check failed jobs in that driver\'s own tooling.',
            );
        }

        return $queue;
    }

    private function assertHours(int $hours): void {
        if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
            throw new ToolCallException(
                'hours must be between ' . self::MIN_HOURS . ' and ' . self::MAX_HOURS . ", got {$hours}.",
            );
        }
    }
}
