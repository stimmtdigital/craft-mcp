<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Closure;
use Craft;
use Generator;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use ReflectionClass;
use ReflectionFunction;
use stimmt\craft\Mcp\support\FileHelper;
use Throwable;
use yii\base\Event;

/**
 * Debugging tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class DebugTools {
    /**
     * List queue jobs (pending, failed, reserved).
     */
    #[McpTool(
        name: 'get_queue_jobs',
        description: 'List queue jobs in Craft CMS. Filter by status: pending, reserved, failed, done. Shows job class, description, and failure reason if applicable.',
    )]
    public function getQueueJobs(string $status = 'pending', int $limit = 50): array {
        $db = Craft::$app->getDb();
        $prefix = $db->tablePrefix;
        $table = $prefix . 'queue';

        try {
            $query = match ($status) {
                'pending' => "SELECT id, channel, job, description, timePushed, ttr, delay, priority
                              FROM `{$table}`
                              WHERE fail = 0 AND timeUpdated IS NULL
                              ORDER BY priority, timePushed
                              LIMIT {$limit}",
                'reserved' => "SELECT id, channel, job, description, timePushed, timeUpdated, ttr, progress, progressLabel
                               FROM `{$table}`
                               WHERE fail = 0 AND timeUpdated IS NOT NULL
                               ORDER BY timeUpdated DESC
                               LIMIT {$limit}",
                'failed' => "SELECT id, channel, job, description, timePushed, timeUpdated, ttr, attempt, error
                             FROM `{$table}`
                             WHERE fail = 1
                             ORDER BY timeUpdated DESC
                             LIMIT {$limit}",
                'done' => "SELECT COUNT(*) as count FROM `{$table}` WHERE fail = 0",
                default => throw new InvalidArgumentException("Invalid status: {$status}"),
            };

            $results = $db->createCommand($query)->queryAll();

            // Parse job class from serialized data
            foreach ($results as &$row) {
                if (isset($row['job'])) {
                    // Extract class name from serialized PHP object
                    if (preg_match('/^O:\d+:"([^"]+)"/', $row['job'], $matches)) {
                        $row['jobClass'] = $matches[1];
                    }
                    unset($row['job']); // Don't return the full serialized blob
                }
                if (isset($row['timePushed'])) {
                    $row['timePushed'] = date('Y-m-d H:i:s', (int) $row['timePushed']);
                }
                if (isset($row['timeUpdated']) && $row['timeUpdated']) {
                    $row['timeUpdated'] = date('Y-m-d H:i:s', (int) $row['timeUpdated']);
                }
            }

            // Get counts for context
            $counts = [
                'pending' => (int) $db->createCommand(
                    "SELECT COUNT(*) FROM `{$table}` WHERE fail = 0 AND timeUpdated IS NULL",
                )->queryScalar(),
                'reserved' => (int) $db->createCommand(
                    "SELECT COUNT(*) FROM `{$table}` WHERE fail = 0 AND timeUpdated IS NOT NULL",
                )->queryScalar(),
                'failed' => (int) $db->createCommand(
                    "SELECT COUNT(*) FROM `{$table}` WHERE fail = 1",
                )->queryScalar(),
            ];

            return [
                'status' => $status,
                'count' => count($results),
                'counts' => $counts,
                'jobs' => $results,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get project config differences (pending changes).
     */
    #[McpTool(
        name: 'get_project_config_diff',
        description: 'Show pending project config changes that need to be applied. Returns differences between YAML files and database.',
    )]
    public function getProjectConfigDiff(): array {
        try {
            $projectConfig = Craft::$app->getProjectConfig();

            // Check if there are pending changes
            $areChangesPending = $projectConfig->areChangesPending();

            if (!$areChangesPending) {
                return [
                    'pending' => false,
                    'message' => 'Project config is up to date',
                ];
            }

            // Get the pending changes
            $pendingChanges = [];

            // Compare YAML to DB
            $yamlConfig = $projectConfig->get();
            $dbConfig = $projectConfig->get(null, true); // true = from DB

            // Find differences (simplified - just top-level keys)
            $yamlKeys = array_keys($yamlConfig ?? []);
            $dbKeys = array_keys($dbConfig ?? []);

            $added = array_diff($yamlKeys, $dbKeys);
            $removed = array_diff($dbKeys, $yamlKeys);
            $modified = [];

            foreach (array_intersect($yamlKeys, $dbKeys) as $key) {
                if (json_encode($yamlConfig[$key]) !== json_encode($dbConfig[$key])) {
                    $modified[] = $key;
                }
            }

            return [
                'pending' => true,
                'summary' => [
                    'added' => count($added),
                    'removed' => count($removed),
                    'modified' => count($modified),
                ],
                'changes' => [
                    'added' => array_values($added),
                    'removed' => array_values($removed),
                    'modified' => array_values($modified),
                ],
                'hint' => 'Run `php craft project-config/apply` to apply changes',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get deprecation warnings from logs.
     */
    #[McpTool(
        name: 'get_deprecations',
        description: 'Get deprecation warnings from Craft CMS logs. Shows deprecated code usage that should be updated.',
    )]
    public function getDeprecations(int $limit = 50): array {
        $logPath = Craft::$app->getPath()->getLogPath();
        $webLog = $logPath . '/web.log';

        $deprecations = [];

        if (file_exists($webLog)) {
            $lines = FileHelper::tail($webLog, $limit * 5);

            foreach ($lines as $line) {
                // Look for deprecation warnings
                if (
                    (stripos($line, 'deprecated') !== false || stripos($line, 'deprecation') !== false) && preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\[([^\]]*)\]\s*(.*)$/s', $line, $matches)
                ) {
                    $deprecations[] = [
                        'timestamp' => $matches[1],
                        'level' => $matches[2],
                        'category' => $matches[3],
                        'message' => trim($matches[4]),
                    ];
                }
            }
        }

        // Also check deprecation errors table if it exists
        try {
            $db = Craft::$app->getDb();
            $prefix = $db->tablePrefix;
            $table = $prefix . 'deprecationerrors';

            $dbDeprecations = $db->createCommand(
                "SELECT id, `key`, fingerprint, lastOccurrence, file, line, message, template
                 FROM `{$table}`
                 ORDER BY lastOccurrence DESC
                 LIMIT {$limit}",
            )->queryAll();

            foreach ($dbDeprecations as &$dep) {
                if ($dep['lastOccurrence']) {
                    $dep['lastOccurrence'] = date('Y-m-d H:i:s', strtotime((string) $dep['lastOccurrence']));
                }
            }
        } catch (Throwable) {
            $dbDeprecations = [];
        }

        // Limit and dedupe log deprecations
        $deprecations = array_slice($deprecations, 0, $limit);

        return [
            'fromDatabase' => [
                'count' => count($dbDeprecations),
                'deprecations' => $dbDeprecations,
            ],
            'fromLogs' => [
                'count' => count($deprecations),
                'deprecations' => $deprecations,
            ],
            'hint' => 'Database deprecations persist until fixed. Clear with `php craft clear-deprecations`.',
        ];
    }

    /**
     * Run EXPLAIN on a SQL query for performance analysis.
     */
    #[McpTool(
        name: 'explain_query',
        description: 'Run EXPLAIN on a SELECT query to analyze performance. Shows query execution plan, indexes used, and estimated rows.',
    )]
    public function explainQuery(string $sql): array {
        // Security: Only allow SELECT queries
        $trimmedSql = trim($sql);
        $upperSql = strtoupper($trimmedSql);

        if (!str_starts_with($upperSql, 'SELECT')) {
            return [
                'success' => false,
                'error' => 'Only SELECT queries can be explained',
            ];
        }

        // Block dangerous keywords
        $blockedKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE'];
        foreach ($blockedKeywords as $keyword) {
            if (str_contains($upperSql, $keyword)) {
                return [
                    'success' => false,
                    'error' => "Query contains blocked keyword: {$keyword}",
                ];
            }
        }

        try {
            $db = Craft::$app->getDb();
            $driver = $db->getDriverName();

            // Build EXPLAIN query based on driver
            $explainSql = match ($driver) {
                'mysql' => "EXPLAIN {$trimmedSql}",
                'pgsql' => "EXPLAIN (ANALYZE false, FORMAT JSON) {$trimmedSql}",
                default => "EXPLAIN {$trimmedSql}",
            };

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
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get safe environment information.
     */
    #[McpTool(
        name: 'get_environment',
        description: 'Get safe environment information (no secrets). Shows CRAFT_ENVIRONMENT, PHP settings, and system status.',
    )]
    public function getEnvironment(): array {
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
        name: 'list_event_handlers',
        description: 'List registered event handlers/listeners in Craft CMS. Useful for debugging hooks and understanding what code runs on events.',
    )]
    public function listEventHandlers(?string $filter = null): array {
        $handlers = $this->getApplicationEvents($filter);
        $classEvents = $this->getClassEvents($filter);

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

    /**
     * Get application-level event handlers.
     */
    private function getApplicationEvents(?string $filter): array {
        try {
            $reflection = new ReflectionClass(Craft::$app);
            $eventsProperty = $reflection->getProperty('_events');
            $events = $eventsProperty->getValue(Craft::$app) ?? [];
        } catch (Throwable) {
            return [];
        }

        $handlers = [];
        foreach ($events as $eventName => $eventHandlers) {
            if ($filter !== null && stripos((string) $eventName, $filter) === false) {
                continue;
            }

            $handlers[$eventName] = [
                'count' => count($eventHandlers),
                'handlers' => $this->describeHandlers($eventHandlers),
            ];
        }

        return $handlers;
    }

    /**
     * Get class-level event handlers (Events::on()).
     */
    private function getClassEvents(?string $filter): array {
        try {
            $eventReflection = new ReflectionClass(Event::class);
            $classEventsProperty = $eventReflection->getStaticPropertyValue('_events');
        } catch (Throwable) {
            return [];
        }

        $classEvents = [];
        foreach ($this->flattenClassEvents($classEventsProperty, $filter) as $event) {
            $key = "{$event['class']}::{$event['event']}";
            $classEvents[$key] = $event;
        }

        return $classEvents;
    }

    /**
     * Flatten nested class events structure.
     *
     * @return Generator<array{class: string, event: string, count: int, handlers: array}>
     */
    private function flattenClassEvents(array $classEventsProperty, ?string $filter): Generator {
        foreach ($classEventsProperty as $className => $classEventHandlers) {
            foreach ($classEventHandlers as $eventName => $eventHandlerList) {
                if ($filter !== null &&
                    stripos((string) $eventName, $filter) === false &&
                    stripos((string) $className, $filter) === false) {
                    continue;
                }

                yield [
                    'class' => $className,
                    'event' => $eventName,
                    'count' => count($eventHandlerList),
                    'handlers' => $this->describeHandlers($eventHandlerList),
                ];
            }
        }
    }

    /**
     * Describe a list of event handlers.
     */
    private function describeHandlers(array $handlers): array {
        return array_map(
            fn (array $handler) => $this->describeCallback($handler[0]),
            $handlers,
        );
    }

    /**
     * Describe a callback for human readability.
     */
    private function describeCallback(mixed $callback): string {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            $class = is_object($callback[0]) ? $callback[0]::class : $callback[0];
            $method = $callback[1];

            return "{$class}::{$method}()";
        }

        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);
            $file = basename($reflection->getFileName());
            $line = $reflection->getStartLine();

            return "Closure in {$file}:{$line}";
        }

        if (is_object($callback)) {
            return $callback::class . '::__invoke()';
        }

        return 'unknown';
    }
}
