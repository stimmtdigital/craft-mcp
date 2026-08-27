<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\pipeline\Presenter;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SqlReadGuard;
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
        title: 'Database tables and columns',
        description: 'Get database schema information. Lists all tables, or details for a specific table including columns and indexes.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE, privileged: true)]
    public function getDatabaseSchema(
        #[Schema(description: 'Table name, with or without the install\'s table prefix. Omit to list every table instead of detailing one.')]
        ?string $table = null,
        ?RequestContext $context = null,
    ): array {
        $db = Craft::$app->getDb();
        $schema = $db->getSchema();
        $tablePrefix = $db->tablePrefix;

        if ($table !== null) {
            // Get specific table details
            $fullTableName = $tablePrefix . $table;
            $tableSchema = $schema->getTableSchema($fullTableName);

            // Try without prefix
            $tableSchema ??= $schema->getTableSchema($table);

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
    }

    /**
     * Blocked SQL keywords.
     */
    /**
     * Execute a read-only SQL query.
     *
     * WARNING: Basic keyword-based security. Can potentially be bypassed
     * with multi-statement queries if PDO settings allow. For development use only.
     */
    #[McpTool(
        name: 'run_query',
        title: 'Run a read-only SQL query',
        description: 'Execute a read-only SQL query (SELECT only). Best for custom plugin tables and aggregate SQL; for table and column discovery use get_database_schema, and for entry content prefer list_entries/count_entries. WARNING: Basic keyword security - for development only. May be bypassable with certain PDO configs.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE, dangerous: true)]
    public function runQuery(
        #[Schema(description: 'The SELECT statement to run. Anything the read guard does not recognise as read-only is refused before execution.')]
        string $sql,
        #[Schema(description: 'Row cap, appended as a LIMIT clause only when the statement does not already carry one.')]
        int $limit = 100,
        #[Schema(description: Presenter::OUTPUT_DESCRIPTION)]
        ResponseFormat $output = ResponseFormat::STRUCTURED,
        ?RequestContext $context = null,
    ): array {
        $context?->getClientGateway()?->progress(0, 2, 'Executing SQL query...');

        $trimmedSql = SqlReadGuard::assertSelectOnly($sql);
        $context?->getClientLogger()?->info('SQL query validated by the read guard');

        // Add LIMIT if not present
        if (!preg_match('/\bLIMIT\b/i', $trimmedSql)) {
            $sql = rtrim($trimmedSql, ';') . " LIMIT {$limit}";
        }

        $context?->getClientLogger()?->debug("SQL query text: {$sql}");

        $db = Craft::$app->getDb();
        $results = $db->createCommand($sql)->queryAll();
        $rowCount = count($results);

        $context?->getClientLogger()?->info("SQL query returned {$rowCount} rows");
        $context?->getClientGateway()?->progress(2, 2, 'Query complete');

        return Response::success([
            'count' => $rowCount,
            'columns' => empty($results) ? [] : array_keys($results[0]),
            'rows' => $results,
        ]);
    }

    /**
     * Get database connection info.
     */
    #[McpTool(
        name: 'get_database_info',
        title: 'Database connection',
        description: 'Get database connection information including driver, server version, and connection details',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE, privileged: true)]
    public function getDatabaseInfo(?RequestContext $context = null): array {
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
    }

    /**
     * Get table row counts.
     */
    #[McpTool(
        name: 'get_table_counts',
        title: 'Table row counts',
        description: 'Get row counts for Craft CMS tables (entries, assets, users, etc.)',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::DATABASE, privileged: true)]
    public function getTableCounts(
        #[Schema(description: Presenter::OUTPUT_DESCRIPTION)]
        ResponseFormat $output = ResponseFormat::STRUCTURED,
        ?RequestContext $context = null,
    ): array {
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
            $fullTable = $prefix . $table;

            // Skip tables that genuinely don't exist; let real query
            // failures surface via SafeExecution instead of masking them.
            if ($db->getTableSchema($fullTable) === null) {
                $counts[$table] = [
                    'label' => $label,
                    'count' => null,
                    'error' => 'Table does not exist',
                ];

                continue;
            }

            $count = $db->createCommand("SELECT COUNT(*) FROM `{$fullTable}`")->queryScalar();
            $counts[$table] = [
                'label' => $label,
                'count' => (int) $count,
            ];
        }

        return $counts;
    }
}
