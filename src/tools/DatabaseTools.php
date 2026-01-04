<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SafeExecution;
use Throwable;

/**
 * Database-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class DatabaseTools {
    /**
     * Get database schema information.
     */
    #[McpTool(
        name: 'get_database_schema',
        description: 'Get database schema information. Lists all tables, or details for a specific table including columns and indexes.',
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE)]
    public function getDatabaseSchema(?string $table = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($table): array {
            $db = Craft::$app->getDb();
            $schema = $db->getSchema();
            $tablePrefix = $db->tablePrefix;

            if ($table !== null) {
                // Get specific table details
                $fullTableName = $tablePrefix . $table;
                $tableSchema = $schema->getTableSchema($fullTableName);

                if ($tableSchema === null) {
                    // Try without prefix
                    $tableSchema = $schema->getTableSchema($table);
                }

                if ($tableSchema === null) {
                    throw new ToolCallException("Table '{$table}' not found");
                }

                $columns = [];
                foreach ($tableSchema->columns as $column) {
                    $columns[] = [
                        'name' => $column->name,
                        'type' => $column->type,
                        'dbType' => $column->dbType,
                        'phpType' => $column->phpType,
                        'allowNull' => $column->allowNull,
                        'defaultValue' => $column->defaultValue,
                        'isPrimaryKey' => $column->isPrimaryKey,
                        'autoIncrement' => $column->autoIncrement,
                        'size' => $column->size,
                    ];
                }

                $indexes = [];

                try {
                    $tableIndexes = $schema->findIndexes($tableSchema->fullName);
                    foreach ($tableIndexes as $indexName => $index) {
                        $indexes[] = [
                            'name' => $indexName,
                            'columns' => $index,
                        ];
                    }
                } catch (Throwable) {
                    // Index retrieval not supported on all DB types
                }

                return [
                    'table' => $tableSchema->name,
                    'fullName' => $tableSchema->fullName,
                    'primaryKey' => $tableSchema->primaryKey,
                    'foreignKeys' => $tableSchema->foreignKeys,
                    'columns' => $columns,
                    'indexes' => $indexes,
                ];
            }

            // List all tables
            $tableNames = $schema->getTableNames();
            $tables = [];

            foreach ($tableNames as $tableName) {
                $displayName = $tableName;
                if ($tablePrefix && str_starts_with((string) $tableName, (string) $tablePrefix)) {
                    $displayName = substr((string) $tableName, strlen((string) $tablePrefix));
                }

                $tables[] = [
                    'name' => $displayName,
                    'fullName' => $tableName,
                ];
            }

            // Sort alphabetically
            usort($tables, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

            return [
                'driver' => $db->getDriverName(),
                'tablePrefix' => $tablePrefix,
                'count' => count($tables),
                'tables' => $tables,
            ];
        });
    }

    /**
     * Blocked SQL keywords.
     */
    private const array BLOCKED_SQL_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE',
        'ALTER', 'CREATE', 'GRANT', 'REVOKE', 'INTO OUTFILE',
    ];

    /**
     * Execute a read-only SQL query.
     *
     * WARNING: Basic keyword-based security. Can potentially be bypassed
     * with multi-statement queries if PDO settings allow. For development use only.
     */
    #[McpTool(
        name: 'run_query',
        description: 'Execute a read-only SQL query (SELECT only). WARNING: Basic keyword security - for development only. May be bypassable with certain PDO configs.',
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE, dangerous: true)]
    public function runQuery(string $sql, int $limit = 100, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($sql, $limit, $context): array {
            $context?->getClientGateway()?->progress(0, 2, 'Executing SQL query...');

            $trimmedSql = trim($sql);
            $upperSql = strtoupper($trimmedSql);

            if (!str_starts_with($upperSql, 'SELECT')) {
                throw new ToolCallException('Only SELECT queries are allowed for safety.');
            }

            foreach (self::BLOCKED_SQL_KEYWORDS as $keyword) {
                if (str_contains($upperSql, $keyword)) {
                    throw new ToolCallException("Query contains blocked keyword: {$keyword}");
                }
            }

            // Add LIMIT if not present
            if (!str_contains($upperSql, 'LIMIT')) {
                $sql = rtrim($trimmedSql, ';') . " LIMIT {$limit}";
            }

            $db = Craft::$app->getDb();
            $results = $db->createCommand($sql)->queryAll();

            $context?->getClientGateway()?->progress(2, 2, 'Query complete');

            return Response::success([
                'count' => count($results),
                'columns' => empty($results) ? [] : array_keys($results[0]),
                'rows' => $results,
            ]);
        });
    }

    /**
     * Get database connection info.
     */
    #[McpTool(
        name: 'get_database_info',
        description: 'Get database connection information including driver, server version, and connection details',
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE)]
    public function getDatabaseInfo(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $db = Craft::$app->getDb();
            $config = Craft::$app->getConfig()->getDb();

            return [
                'driver' => $db->getDriverName(),
                'serverVersion' => $db->getServerVersion(),
                'server' => $config->server,
                'port' => $config->port,
                'database' => $config->database,
                'tablePrefix' => $config->tablePrefix,
                'charset' => $config->charset,
            ];
        });
    }

    /**
     * Get table row counts.
     */
    #[McpTool(
        name: 'get_table_counts',
        description: 'Get row counts for Craft CMS tables (entries, assets, users, etc.)',
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE)]
    public function getTableCounts(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $db = Craft::$app->getDb();
            $prefix = $db->tablePrefix;

            // Craft 5: Matrix blocks are now nested entries, not separate table
            $tables = [
                'elements' => 'Total elements',
                'entries' => 'Entries',
                'assets' => 'Assets',
                'users' => 'Users',
                'categories' => 'Categories',
                'tags' => 'Tags',
                'globalsets' => 'Global sets',
                'sections' => 'Sections',
                'entrytypes' => 'Entry types',
                'fields' => 'Fields',
                'volumes' => 'Volumes',
                'plugins' => 'Plugins',
            ];

            $counts = [];
            foreach ($tables as $table => $label) {
                try {
                    $fullTable = $prefix . $table;
                    $count = $db->createCommand("SELECT COUNT(*) FROM `{$fullTable}`")->queryScalar();
                    $counts[$table] = [
                        'label' => $label,
                        'count' => (int) $count,
                    ];
                } catch (Throwable) {
                    // Table might not exist
                    $counts[$table] = [
                        'label' => $label,
                        'count' => null,
                        'error' => 'Table not found',
                    ];
                }
            }

            return $counts;
        });
    }
}
