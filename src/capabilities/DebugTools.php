<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\Parser;
use stimmt\craft\Mcp\logging\Search;
use stimmt\craft\Mcp\support\EventHandlers;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SqlReadGuard;
use stimmt\craft\Mcp\support\Window;
use Throwable;

/**
 * Debugging tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class DebugTools {
    /**
     * Substring a log message must contain to count as a deprecation warning.
     */
    private const string DEPRECATION_PATTERN = 'deprecat';

    /**
     * Get deprecation warnings from logs.
     */
    #[McpTool(
        name: 'stimmt_deprecations_list',
        title: 'Deprecation warnings',
        description: 'Get deprecation warnings from Craft CMS logs. Shows deprecated code usage that should be updated.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function getDeprecations(
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 50,
        ?RequestContext $context = null,
    ): array {
        Window::assert($limit);

        $fromLogs = $this->searchLogs($limit, $context);
        $fromDatabase = $this->readDeprecationTable($limit);

        // Neither side reports a total. The log scan stops as soon as it has
        // enough matches, and the table read would need a second query, which
        // is the same "count by reading it twice" this envelope refuses to do.
        return [
            'fromDatabase' => Response::capped('deprecations', $fromDatabase, $limit),
            'fromLogs' => Response::capped('deprecations', $fromLogs, $limit),
            'hint' => 'Database deprecations persist until fixed. Clear with `php craft clear-deprecations`.'
                . ' Logs are searched back at most ' . Parser::scanDepth() . '.',
        ];
    }

    /**
     * Deprecation warnings found in the log files.
     *
     * WHY the parser rather than a regex here: Craft writes rotated files
     * (web-2026-08-28.log, never web.log) in the format the parser already
     * knows, and reads them back newest first until it has enough matches.
     * The regex this replaced expected three bracket groups where a log line
     * opens with a bare timestamp and two, so it could not have matched a real
     * line even if the file it read had existed.
     *
     * WHY one substring instead of two: "deprecat" is the common prefix of
     * "deprecated" and "deprecation", so a single pass catches both spellings.
     *
     * WHY only the logged line: a Craft log entry carries its continuation
     * lines, and a request-context dump runs to hundreds of them. Searching
     * those turned every request that happened to mention the word into a
     * deprecation, this tool's own name in a logged request body included. An
     * entry announces what it is on its first line or it is not one.
     *
     * @return array<array<string, mixed>>
     */
    private function searchLogs(int $limit, ?RequestContext $context): array {
        $parser = new Parser(Craft::$app->getPath()->getLogPath());
        $logger = $context?->getClientLogger();

        $entries = $parser->newest(
            new Search($limit, pattern: self::DEPRECATION_PATTERN, headlineOnly: true),
            static function (string $file, int $position, int $total) use ($logger): void {
                $logger?->info('Searching ' . basename($file) . " ({$position} of {$total})");
            },
        );

        return array_map(static fn (Entry $entry): array => $entry->toArray(), $entries);
    }

    /**
     * Deprecation warnings Craft has recorded in its own table.
     *
     * @return array<array<string, mixed>>
     */
    private function readDeprecationTable(int $limit): array {
        // Guard on table existence rather than catching everything, so a
        // genuine query failure surfaces instead of silently reporting zero.
        $db = Craft::$app->getDb();
        $table = $db->tablePrefix . 'deprecationerrors';

        if ($db->getTableSchema($table) === null) {
            return [];
        }

        $deprecations = $db->createCommand(
            "SELECT id, `key`, fingerprint, lastOccurrence, file, line, message
             FROM `{$table}`
             ORDER BY lastOccurrence DESC
             LIMIT {$limit}",
        )->queryAll();

        foreach ($deprecations as &$dep) {
            if ($dep['lastOccurrence']) {
                $dep['lastOccurrence'] = date('Y-m-d H:i:s', strtotime((string) $dep['lastOccurrence']));
            }
        }

        unset($dep);

        return $deprecations;
    }

    /**
     * Run EXPLAIN on a SQL query for performance analysis.
     */
    #[McpTool(
        name: 'stimmt_db_explain',
        title: 'Explain a query plan',
        description: 'Run EXPLAIN on a SELECT query to analyze performance. Shows query execution plan, indexes used, and estimated rows.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function explainQuery(
        #[Schema(description: 'The SELECT statement to explain. It is not executed, but anything the read guard does not recognise as read-only is refused anyway.')]
        string $sql,
        ?RequestContext $context = null,
    ): array {
        // Security: only allow read-only SELECT queries
        $trimmedSql = SqlReadGuard::assertSelectOnly($sql);
        $context?->getClientLogger()?->info('SQL query validated by the read guard');
        $context?->getClientLogger()?->debug("SQL query text: {$trimmedSql}");

        $db = Craft::$app->getDb();
        $driver = $db->getDriverName();

        // Build EXPLAIN query based on driver
        $explainSql = match ($driver) {
            'mysql' => "EXPLAIN {$trimmedSql}",
            'pgsql' => "EXPLAIN (ANALYZE false, FORMAT JSON) {$trimmedSql}",
            default => "EXPLAIN {$trimmedSql}",
        };

        $context?->getClientLogger()?->info("EXPLAIN issued for driver: {$driver}");

        $results = $db->createCommand($explainSql)->queryAll();

        // For MySQL, also get extended info
        $warnings = [];
        if ($driver === 'mysql') {
            try {
                $warnings = $db->createCommand('SHOW WARNINGS')->queryAll();
            } catch (Throwable) {
                // Ignore if not supported
            }
        }

        return [
            'success' => true,
            'driver' => $driver,
            'query' => $trimmedSql,
            'explain' => $results,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get safe environment information.
     */
    #[McpTool(
        name: 'stimmt_environment_get',
        title: 'Environment and PHP settings',
        description: 'Get safe environment information (no secrets). Shows CRAFT_ENVIRONMENT, PHP settings, and system status.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function getEnvironment(?RequestContext $context = null): array {
        $general = Craft::$app->getConfig()->getGeneral();

        // Safe environment variables to expose
        $safeEnvVars = [
            'CRAFT_ENVIRONMENT' => getenv('CRAFT_ENVIRONMENT') ?: 'production',
            'CRAFT_DEV_MODE' => getenv('CRAFT_DEV_MODE') ?: 'false',
            'CRAFT_ALLOW_ADMIN_CHANGES' => getenv('CRAFT_ALLOW_ADMIN_CHANGES') ?: null,
            'CRAFT_RUN_QUEUE_AUTOMATICALLY' => getenv('CRAFT_RUN_QUEUE_AUTOMATICALLY') ?: null,
        ];

        // PHP settings relevant to debugging
        $phpSettings = [
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'display_errors' => ini_get('display_errors'),
            'error_reporting' => ini_get('error_reporting'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'opcache.enable' => ini_get('opcache.enable'),
        ];

        // System status
        $systemStatus = [
            'isSystemLive' => Craft::$app->getIsLive(),
            'devMode' => $general->devMode,
            'cpTrigger' => $general->cpTrigger,
            'runQueueAutomatically' => $general->runQueueAutomatically,
            'allowAdminChanges' => $general->allowAdminChanges,
            'enableTemplateCaching' => $general->enableTemplateCaching,
            'testToEmailAddress' => $general->testToEmailAddress ?: null,
        ];

        // Paths (for debugging path issues)
        $paths = [
            'basePath' => Craft::$app->getBasePath(),
            'configPath' => Craft::$app->getPath()->getConfigPath(),
            'storagePath' => Craft::$app->getPath()->getStoragePath(),
            'templatesPath' => Craft::$app->getPath()->getSiteTemplatesPath(),
        ];

        return [
            'environment' => $safeEnvVars,
            'php' => $phpSettings,
            'system' => $systemStatus,
            'paths' => $paths,
        ];
    }

    /**
     * List registered event handlers.
     */
    #[McpTool(
        name: 'stimmt_event_handlers_list',
        title: 'Registered event handlers',
        description: 'List registered event handlers/listeners in Craft CMS. Useful for debugging hooks and understanding what code runs on events.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function listEventHandlers(
        #[Schema(description: 'Case-insensitive substring matched against event names and the classes the events are registered on.')]
        ?string $filter = null,
        ?RequestContext $context = null,
    ): array {
        $events = new EventHandlers();
        $handlers = $events->applicationEvents($filter);
        $classEvents = $events->classEvents($filter);

        return [
            'applicationEvents' => [
                'count' => count($handlers),
                'events' => $handlers,
            ],
            'classEvents' => [
                'count' => count($classEvents),
                'events' => $classEvents,
            ],
            'hint' => 'Use filter parameter to search by event or class name',
        ];
    }
}
