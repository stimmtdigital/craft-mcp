<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * The ordered script the harness runs: every tool called at least once, with
 * arguments chosen so the call exercises its success path rather than its
 * validation path.
 *
 * WHY an explicit script rather than "call everything with no arguments": a
 * tool that answers with a validation error proves nothing except that it can
 * reject input. The write tools in particular are only meaningful as a
 * lifecycle, so the plan creates a draft, edits it, gives it a block, reorders
 * that block, publishes it, duplicates it and deletes both, threading the ids
 * from each response into the next call.
 *
 * A step that cannot run says so in the snapshot with its reason, so a coverage
 * gap is visible in the file rather than absent from it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Plan {
    /** Tools that write files or take real time. Excluded unless asked for. */
    public const string TAG_HEAVY = 'heavy';

    /** Tools that disturb state the rest of the run depends on. Always last. */
    public const string TAG_PERTURBING = 'perturbing';

    /**
     * The install this plan is written against. Kept in one place so a run on a
     * different install fails loudly on the first content step instead of
     * silently skipping half the plan.
     */
    public const string SECTION = 'pages';

    public const string ENTRY_TYPE = 'page';

    public const string MATRIX_FIELD = 'contentBuilder';

    public const string BLOCK_TYPE = 'contentBlock';

    /**
     * @return list<array<string, mixed>>
     */
    public static function steps(string $runId): array {
        return array_merge(
            self::introspection(),
            self::configuration(),
            self::contentReads(),
            self::writeLifecycle($runId),
            self::perturbing(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function introspection(): array {
        return [
            [
                'tool' => 'get_mcp_info',
                'args' => [],
                'assert' => ['tools.total' => '>=50', 'status.enabled' => true],
            ],
            [
                'tool' => 'list_mcp_tools',
                'args' => [],
                'assert' => ['count' => '>=50', 'tools' => 'notEmpty'],
            ],
            ['tool' => 'get_system_info', 'args' => []],
            ['tool' => 'get_environment', 'args' => []],
            ['tool' => 'list_plugins', 'args' => []],
            ['tool' => 'get_last_error', 'args' => []],
            ['tool' => 'get_deprecations', 'args' => ['limit' => 5]],
            ['tool' => 'get_queue_jobs', 'args' => ['limit' => 5]],
            ['tool' => 'list_console_commands', 'args' => []],
            ['tool' => 'list_routes', 'args' => []],
            ['tool' => 'list_event_handlers', 'args' => ['filter' => 'Entry']],
            ['tool' => 'read_logs', 'args' => ['limit' => 5]],
            ['tool' => 'read_logs', 'name' => 'read_logs.text', 'args' => ['limit' => 5, 'output' => 'text']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function configuration(): array {
        return [
            [
                'tool' => 'list_sites',
                'args' => [],
                'capture' => ['site.handle' => 'sites.0.handle'],
            ],
            ['tool' => 'list_site_groups', 'args' => []],
            ['tool' => 'get_site', 'args' => ['handle' => '{{site.handle}}']],
            [
                'tool' => 'list_sections',
                'args' => [],
                'assert' => ['count' => '>=1', 'sections' => 'notEmpty'],
            ],
            ['tool' => 'list_fields', 'args' => []],
            ['tool' => 'list_volumes', 'args' => []],
            ['tool' => 'list_globals', 'args' => []],
            ['tool' => 'list_categories', 'args' => ['limit' => 5]],
            ['tool' => 'list_users', 'args' => ['limit' => 5]],
            ['tool' => 'get_config', 'args' => ['key' => 'devMode']],
            ['tool' => 'get_project_config_diff', 'args' => []],
            ['tool' => 'get_database_info', 'args' => []],
            ['tool' => 'get_database_schema', 'args' => ['table' => 'entries']],
            ['tool' => 'get_table_counts', 'args' => []],
            ['tool' => 'list_backups', 'args' => []],
            [
                'tool' => 'list_graphql_schemas',
                'args' => [],
                'capture' => ['graphqlSchema.id' => 'schemas.0.id'],
            ],
            ['tool' => 'list_graphql_tokens', 'args' => []],
            ['tool' => 'get_graphql_schema', 'args' => ['id' => '{{graphqlSchema.id}}']],
            ['tool' => 'explain_query', 'args' => ['sql' => 'SELECT id FROM elements LIMIT 1']],
            [
                'tool' => 'run_query',
                'args' => ['sql' => 'SELECT id FROM elements ORDER BY id LIMIT 1'],
                'assert' => ['success' => true, 'rows' => 'notEmpty'],
            ],
            ['tool' => 'run_query', 'name' => 'run_query.text', 'args' => ['sql' => 'SELECT id FROM elements ORDER BY id LIMIT 1', 'output' => 'text']],
            ['tool' => 'tinker', 'args' => ['code' => 'return 1 + 1;']],
            ['tool' => 'query_graphql', 'args' => ['query' => '{ ping }']],
            ['tool' => 'execute_graphql', 'args' => ['query' => '{ ping }']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contentReads(): array {
        return [
            [
                'tool' => 'list_entries',
                'args' => ['section' => self::SECTION, 'limit' => 3],
                'capture' => ['existing.id' => 'entries.0.id', 'existing.slug' => 'entries.0.slug'],
                'assert' => ['total' => '>=1', 'entries' => 'notEmpty', 'entries.0.id' => 'isInt'],
            ],
            [
                'tool' => 'list_entries',
                'name' => 'list_entries.projection',
                'args' => ['section' => self::SECTION, 'limit' => 3, 'fields' => ['slug', 'status']],
            ],
            [
                'tool' => 'list_entries',
                'name' => 'list_entries.search',
                'args' => ['section' => self::SECTION, 'limit' => 3, 'search' => 'a'],
            ],
            [
                'tool' => 'list_entries',
                'name' => 'list_entries.filters',
                'args' => ['section' => self::SECTION, 'limit' => 3, 'filters' => ['category' => ':empty:']],
            ],
            [
                'tool' => 'get_entry',
                'args' => ['id' => '{{existing.id}}'],
                'assert' => ['found' => true, 'entry' => 'notEmpty', 'entry.id' => 'isInt'],
            ],
            [
                'tool' => 'get_entry',
                'name' => 'get_entry.by_slug',
                'args' => ['slug' => '{{existing.slug}}', 'section' => self::SECTION],
                'assert' => ['found' => true, 'entry' => 'notEmpty'],
            ],
            ['tool' => 'count_entries', 'args' => [], 'assert' => ['success' => true, 'total' => '>=1']],
            ['tool' => 'count_entries', 'name' => 'count_entries.grouped', 'args' => ['groupBy' => 'section']],
            [
                'tool' => 'describe_entry_schema',
                'args' => ['section' => self::SECTION],
                'assert' => ['section' => self::SECTION, 'fields' => 'notEmpty', 'natives' => 'present'],
            ],
            ['tool' => 'describe_entry_schema', 'name' => 'describe_entry_schema.example', 'args' => ['section' => self::SECTION, 'example' => '{{existing.id|string}}']],
            ['tool' => 'list_drafts', 'args' => ['limit' => 3]],
            ['tool' => 'list_revisions', 'args' => ['id' => '{{existing.id}}', 'limit' => 3]],
            [
                'tool' => 'list_assets',
                'args' => ['limit' => 3],
                'capture' => ['asset.id' => 'assets.0.id'],
            ],
            ['tool' => 'get_asset', 'args' => ['id' => '{{asset.id}}']],
            ['tool' => 'list_asset_folders', 'args' => []],
        ];
    }

    /**
     * The lifecycle. Each step depends on the one before it, which is the point:
     * this is the only part of the harness that proves the write path end to
     * end rather than one call at a time.
     *
     * @return list<array<string, mixed>>
     */
    private static function writeLifecycle(string $runId): array {
        return [
            [
                'tool' => 'create_entry',
                'args' => [
                    'section' => self::SECTION,
                    'type' => self::ENTRY_TYPE,
                    'title' => "Smoke {$runId}",
                    'slug' => "smoke-{$runId}",
                ],
                'capture' => ['draft.id' => 'draftElementId'],
                'assert' => ['success' => true, 'draftElementId' => 'isInt', 'errors' => 'present', 'warnings' => 'present'],
            ],
            ['tool' => 'get_entry', 'name' => 'get_entry.draft', 'args' => ['id' => '{{draft.id}}']],
            [
                'tool' => 'update_entry',
                'args' => ['id' => '{{draft.id}}', 'title' => "Smoke {$runId} edited"],
                'assert' => ['success' => true, 'draftElementId' => 'isInt'],
            ],
            [
                'tool' => 'create_nested_entry',
                'args' => [
                    'owner' => '{{draft.id}}',
                    'field' => self::MATRIX_FIELD,
                    'type' => self::BLOCK_TYPE,
                ],
                'capture' => ['block.id' => 'blockId'],
                'assert' => ['success' => true, 'blockId' => 'isInt', 'position' => 'isInt'],
            ],
            [
                'tool' => 'create_nested_entry',
                'name' => 'create_nested_entry.second',
                'args' => [
                    'owner' => '{{draft.id}}',
                    'field' => self::MATRIX_FIELD,
                    'type' => self::BLOCK_TYPE,
                ],
                'capture' => ['block.second' => 'blockId'],
                'assert' => ['success' => true, 'blockId' => 'isInt'],
            ],
            [
                'tool' => 'move_nested_entry',
                'args' => ['id' => '{{block.second}}', 'position' => 1],
                'assert' => ['success' => true, 'position' => 1],
            ],
            [
                'tool' => 'publish_entry',
                'args' => ['id' => '{{draft.id}}'],
                'capture' => ['published.id' => 'entry.id'],
                'assert' => ['success' => true, 'entry.id' => 'isInt', 'entry.draftId' => null],
            ],
            [
                'tool' => 'duplicate_entry',
                'args' => ['id' => '{{published.id}}', 'title' => "Smoke {$runId} copy"],
                // Note the asymmetry: duplicate_entry answers with a whole
                // entry, where create_entry and update_entry answer with
                // draftElementId. Pinned here so the difference is deliberate
                // rather than discovered again. See NOTES.md.
                'capture' => ['copy.id' => 'entry.id'],
                'assert' => ['success' => true, 'entry.id' => 'isInt', 'entry.draftId' => 'isInt'],
            ],
            [
                'tool' => 'copy_entry_to_site',
                'args' => [],
                'skip' => 'needs a second site; this install has one',
            ],
            [
                'tool' => 'delete_entry',
                'name' => 'delete_entry.copy',
                'args' => ['id' => '{{copy.id}}'],
                'assert' => ['success' => true, 'deleted' => 'present'],
            ],
            [
                'tool' => 'delete_entry',
                'name' => 'delete_entry.published',
                'args' => ['id' => '{{published.id}}'],
                'assert' => ['success' => true, 'deleted' => 'present'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function perturbing(): array {
        return [
            [
                'tool' => 'create_backup',
                'args' => [],
                'tag' => self::TAG_HEAVY,
                'skip' => 'writes a database dump; run with --heavy',
            ],
            [
                'tool' => 'reload_mcp',
                'args' => [],
                'tag' => self::TAG_PERTURBING,
                'assert' => ['success' => true, 'tools.total' => '>=50'],
            ],
            ['tool' => 'clear_caches', 'args' => [], 'tag' => self::TAG_PERTURBING],
        ];
    }
}
