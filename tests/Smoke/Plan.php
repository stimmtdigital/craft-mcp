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

    /** An Entries relation field on the section above, used to prove key resolution. */
    public const string RELATION_FIELD = 'category';

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
                'capture' => ['mcp.available' => 'tools.available'],
                'assert' => [
                    'tools.total' => '>=50',
                    'status.enabled' => true,
                    'tools.available' => '>=1',
                    'connection.transport' => 'notEmpty',
                    'buildSource' => 'notEmpty',
                ],
            ],
            [
                'tool' => 'get_mcp_info',
                'name' => 'get_mcp_info.detail',
                'args' => ['detail' => true],
                'assert' => ['tools.total' => '>=50', 'health.registrationErrors' => 0],
            ],
            [
                'tool' => 'list_mcp_tools',
                'args' => [],
                // The two tools that answer "what may I call" have to answer it
                // the same. They did not: on a readonly connection this one
                // counted every registered tool and called 42 callable ones 55,
                // because it asked the settings instead of the Gate.
                'assert' => [
                    'count' => '>=50',
                    'tools' => 'notEmpty',
                    'available' => '{{mcp.available}}',
                    // Every row says which edition it needs, so a tool that
                    // stopped carrying one is visible here rather than only in
                    // whatever it silently allowed.
                    'tools.0.requiredEdition' => 'notEmpty',
                    'tools.0.locked' => false,
                ],
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
                // The second handle is what makes the multi-site steps run at
                // all. On a single-site install it captures nothing, every step
                // that names it reports itself skipped, and the snapshot says
                // which coverage this install could not provide.
                'capture' => [
                    'site.handle' => 'sites.0.handle',
                    'site.second' => 'sites.1.handle',
                ],
            ],
            ['tool' => 'list_site_groups', 'args' => []],
            ['tool' => 'get_site', 'args' => ['handle' => '{{site.handle}}']],
            [
                'tool' => 'get_site',
                'name' => 'get_site.second',
                'args' => ['handle' => '{{site.second}}'],
                // Two sites must not read as one. If this ever came back as the
                // primary, every per-site read below would be reading the same
                // content twice and passing while proving nothing.
                'assert' => ['success' => true, 'site.primary' => false, 'site.handle' => '{{site.second}}'],
            ],
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
                // Writes land as drafts, so relating to an entry created moments
                // earlier is the ordinary case, not an edge one. It silently
                // dropped the relation with a warning until Keys learned to see
                // unpublished drafts, so both halves are pinned here: the write
                // resolves the key, and the read gives a key back rather than a
                // bare id.
                'tool' => 'create_entry',
                'name' => 'create_entry.relating_to_draft',
                'args' => [
                    'section' => self::SECTION,
                    'type' => self::ENTRY_TYPE,
                    'title' => "Smoke {$runId} relation",
                    'slug' => "smoke-{$runId}-relation",
                    'fields' => '{"' . self::RELATION_FIELD . '":[{"section":"' . self::SECTION . '","slug":"smoke-' . $runId . '"}]}',
                ],
                'capture' => ['relating.id' => 'draftElementId'],
                'assert' => ['success' => true, 'warnings' => [], 'errors' => []],
            ],
            [
                'tool' => 'get_entry',
                'name' => 'get_entry.relation_reads_back_as_a_key',
                'args' => ['id' => '{{relating.id}}'],
                'assert' => [
                    'found' => true,
                    'entry.fields.' . self::RELATION_FIELD . '.0.section' => self::SECTION,
                    'entry.fields.' . self::RELATION_FIELD . '.0.slug' => 'smoke-' . $runId,
                ],
            ],
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
                // A block's position is shared across sites, so the response
                // has to say which sites the new order landed on. An empty or
                // missing list means the reach went unreported, which is the
                // state this whole field exists to prevent.
                'assert' => ['success' => true, 'position' => 1, 'affectedSites' => 'notEmpty'],
            ],
            [
                'tool' => 'publish_entry',
                'args' => ['id' => '{{draft.id}}'],
                'capture' => ['published.id' => 'entry.id'],
                'assert' => [
                    'success' => true,
                    'entry.id' => 'isInt',
                    'entry.draftId' => null,
                    'affectedSites' => 'notEmpty',
                ],
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
            // The published entry propagated to every site its section serves,
            // so it can be read there. Reading it on the second site proves the
            // site argument reaches the query rather than being accepted and
            // dropped, which a single-site run cannot tell apart.
            [
                'tool' => 'get_entry',
                'name' => 'get_entry.second_site',
                'args' => ['id' => '{{published.id}}', 'site' => '{{site.second}}'],
                // Naming the handle is the point: a server that accepted the
                // site argument and read the primary anyway would satisfy
                // "notEmpty" and fail this.
                'assert' => [
                    'found' => true,
                    'entry.id' => 'isInt',
                    'entry.siteHandle' => '{{site.second}}',
                ],
            ],
            [
                'tool' => 'copy_entry_to_site',
                'args' => [
                    'id' => '{{published.id}}',
                    'fromSite' => '{{site.handle}}',
                    'toSite' => '{{site.second}}',
                ],
                'capture' => ['crossSite.draftId' => 'draftElementId'],
                // A copy lands as a draft like every other write, so nothing
                // reaches the second site's live content without review.
                'assert' => ['success' => true, 'state' => 'draft', 'draftElementId' => 'isInt'],
            ],
            [
                'tool' => 'delete_entry',
                'name' => 'delete_entry.cross_site_draft',
                'args' => ['id' => '{{crossSite.draftId}}'],
                'assert' => ['success' => true],
            ],
            [
                'tool' => 'delete_entry',
                'name' => 'delete_entry.relating',
                'args' => ['id' => '{{relating.id}}'],
                'assert' => ['success' => true],
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
                'assert' => ['success' => true, 'deleted' => 'present', 'affectedSites' => 'notEmpty'],
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
