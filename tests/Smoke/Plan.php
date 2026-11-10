<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * The ordered script the harness runs: every `stimmt_` tool called at least
 * once, with arguments chosen so the call exercises its success path rather
 * than its validation path.
 *
 * WHY an explicit script rather than "call everything with no arguments": a
 * tool that answers with a validation error proves nothing except that it can
 * reject input. The write tools in particular are only meaningful against a
 * real draft, so the plan makes one with Craft's own `drafts_create`, threads
 * the ids from each response into the next call, and deletes every draft it
 * made with Craft's `drafts_delete`.
 *
 * A step runs on every profile whose connection advertises its tool, and
 * reports itself skipped on the others, so one plan serves all four profiles
 * and the scope a profile carries shows up as which steps it could run.
 *
 * Step keys: `tool`, `name` (defaults to the tool), `args`, `capture`
 * (variable => payload path), `assert` (see Assert), `refusal` (the call must
 * be turned down, naming this text), `uri` (read a resource instead of calling
 * a tool), `asText` (the answer is text, not JSON), `secret` (assertion
 * failures withhold the value), `skip`, `tag`, `profiles`.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Plan {
    /** Tools that write files or take real time. Excluded unless asked for. */
    public const string TAG_HEAVY = 'heavy';

    /**
     * The fixture this plan is written against: a section of its own, named for
     * the harness so it cannot collide with a real site's content, installed by
     * fixture.php (see NOTES.md). Kept in one place so a run on an install
     * without it fails loudly on the first content step instead of silently
     * skipping half the plan.
     */
    public const string SECTION = 'mcpSmoke';

    public const string ENTRY_TYPE = 'mcpSmokePage';

    public const string MATRIX_FIELD = 'mcpSmokeBlocks';

    public const string BLOCK_TYPE = 'mcpSmokeBlock';

    /** An Entries relation field on the section above, for the field filter. */
    public const string RELATION_FIELD = 'mcpSmokeRelated';

    /** Required in the page type's layout, so a draft that empties it is refused at publish (#76). */
    public const string REQUIRED_FIELD = 'mcpSmokeRequired';

    /** The one live entry the fixture creates, for every step that needs an entry. */
    public const string FIXTURE_SLUG = 'smoke-fixture';

    /** What stimmt_config_get answers in place of a credential (support/Secrets::PLACEHOLDER). */
    private const string REDACTED = '[redacted: this setting holds a credential]';

    /** A preview link: an absolute url carrying a token parameter. Its value is never recorded. */
    private const string PREVIEW_URL = 'matches:/^https?:\/\/[^\s?]+\?(?:[^\s]*&)?token=[^&\s]+/';

    private const string SELECT = 'SELECT id FROM elements ORDER BY id LIMIT 1';

    /**
     * The handles fixture.php installs, passed to the Craft side as one JSON
     * argument so this class stays the one place they are named.
     *
     * @return array<string, string>
     */
    public static function fixture(): array {
        return [
            'SECTION' => self::SECTION,
            'ENTRY_TYPE' => self::ENTRY_TYPE,
            'MATRIX_FIELD' => self::MATRIX_FIELD,
            'BLOCK_TYPE' => self::BLOCK_TYPE,
            'RELATION_FIELD' => self::RELATION_FIELD,
            'REQUIRED_FIELD' => self::REQUIRED_FIELD,
            'FIXTURE_SLUG' => self::FIXTURE_SLUG,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function steps(string $runId): array {
        return array_merge(
            self::contentReads(),
            self::developerReads(),
            self::database(),
            self::graphql(),
            self::publishing($runId),
            self::nestedEntries(),
            self::refusedPublish($runId),
            self::copyToSite(),
        );
    }

    /**
     * The read-scope tools, which every profile reaches. The fixture's id is
     * captured here for everything after.
     *
     * @return list<array<string, mixed>>
     */
    private static function contentReads(): array {
        return [
            [
                'tool' => 'stimmt_refs_resolve',
                'args' => ['type' => 'entry', 'key' => ['section' => self::SECTION, 'slug' => self::FIXTURE_SLUG]],
                'capture' => ['fixture.id' => 'id'],
                'assert' => ['type' => 'entry', 'id' => 'isInt', 'key.slug' => self::FIXTURE_SLUG],
            ],
            ['tool' => 'stimmt_entries_count', 'args' => [], 'assert' => ['total' => '>=1', 'groupBy' => null]],
            [
                'tool' => 'stimmt_entries_count',
                'name' => 'stimmt_entries_count.grouped',
                'args' => ['groupBy' => 'section'],
                'assert' => ['groupBy' => 'section', 'buckets' => 'notEmpty', 'buckets.0.count' => 'isInt'],
            ],
            [
                // The primary site's handle, for the copy step: on a single-site
                // install the only bucket, on any other the first.
                'tool' => 'stimmt_entries_count',
                'name' => 'stimmt_entries_count.by_site',
                'args' => ['section' => self::SECTION, 'groupBy' => 'site'],
                'capture' => ['site.primary' => 'buckets.0.key'],
                'assert' => ['buckets.0.key' => 'isString', 'buckets.0.count' => '>=1'],
            ],
            [
                // A field filter, so the stimmt Filters path is crossed and not
                // only the bare query: the fixture relates to nothing.
                'tool' => 'stimmt_entries_count',
                'name' => 'stimmt_entries_count.filtered',
                'args' => ['section' => self::SECTION, 'filters' => [self::RELATION_FIELD => ':empty:']],
                'assert' => ['total' => '>=1'],
            ],
            ['tool' => 'stimmt_categories_list', 'args' => ['limit' => 5]],
            [
                // The link carries a live preview token. Its shape is asserted
                // and recorded; its value never leaves Shape and Redact.
                'tool' => 'stimmt_entries_preview_url',
                'args' => ['id' => '{{fixture.id}}'],
                'assert' => ['id' => '{{fixture.id}}', 'previewUrl' => self::PREVIEW_URL],
                'secret' => true,
            ],
            [
                'tool' => 'stimmt_tools_guide',
                'name' => 'resource.stimmt_tools_guide',
                'uri' => 'stimmt://guides/tools',
                'assert' => ['contents' => 1, 'mimeType' => 'text/markdown', 'text' => 'matches:/^# /'],
            ],
        ];
    }

    /**
     * The admin-only introspection tools. Values depend on the install, so
     * these are shape, with assertions only where a value must hold anywhere.
     *
     * @return list<array<string, mixed>>
     */
    private static function developerReads(): array {
        return [
            ['tool' => 'stimmt_globals_list', 'args' => []],
            ['tool' => 'stimmt_environment_get', 'args' => []],
            ['tool' => 'stimmt_errors_last', 'args' => []],
            ['tool' => 'stimmt_deprecations_list', 'args' => ['limit' => 5]],
            ['tool' => 'stimmt_console_commands_list', 'args' => []],
            ['tool' => 'stimmt_routes_list', 'args' => []],
            ['tool' => 'stimmt_event_handlers_list', 'args' => ['filter' => 'Entry']],
            ['tool' => 'stimmt_logs_read', 'args' => ['limit' => 5, 'level' => 'error']],
            [
                'tool' => 'stimmt_config_get',
                'args' => ['key' => 'general.devMode'],
                'assert' => ['key' => 'general.devMode', 'value' => 'present'],
            ],
            [
                // A credential comes back named and withheld. Marked secret so
                // that the day redaction breaks, the failure does not print the
                // password it failed to redact.
                'tool' => 'stimmt_config_get',
                'name' => 'stimmt_config_get.credential',
                'args' => ['key' => 'db.password'],
                'assert' => ['key' => 'db.password', 'value' => self::REDACTED],
                'secret' => true,
            ],
            [
                'tool' => 'stimmt_tinker',
                'args' => ['code' => 'return 1 + 1;'],
                'asText' => true,
                'assert' => ['text' => 'matches:/^= 2$/m'],
            ],
            ['tool' => 'stimmt_backups_list', 'args' => []],
            [
                'tool' => 'stimmt_backups_create',
                'args' => [],
                'tag' => self::TAG_HEAVY,
                'skip' => 'writes a database dump; run with --heavy',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function database(): array {
        return [
            ['tool' => 'stimmt_db_info', 'args' => []],
            ['tool' => 'stimmt_db_schema', 'args' => ['table' => 'entries']],
            ['tool' => 'stimmt_db_table_counts', 'args' => []],
            ['tool' => 'stimmt_db_explain', 'args' => ['sql' => self::SELECT]],
            [
                'tool' => 'stimmt_db_query',
                'args' => ['sql' => self::SELECT],
                'assert' => ['success' => true, 'rows' => 'notEmpty'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function graphql(): array {
        return [
            [
                'tool' => 'stimmt_graphql_schemas_list',
                'args' => [],
                'capture' => ['graphqlSchema.id' => 'schemas.0.id'],
            ],
            ['tool' => 'stimmt_graphql_schema_get', 'args' => ['id' => '{{graphqlSchema.id}}']],
            ['tool' => 'stimmt_graphql_tokens_list', 'args' => []],
            [
                'tool' => 'stimmt_graphql_query',
                'args' => ['query' => '{ ping }'],
                'assert' => ['success' => true, 'data.ping' => 'pong'],
            ],
            [
                'tool' => 'stimmt_graphql_execute',
                'args' => ['query' => '{ ping }'],
                'assert' => ['data.ping' => 'pong'],
            ],
        ];
    }

    /**
     * A draft made with Craft's own tool and published with the plugin's,
     * which is the pairing the plugin's tool exists for: Craft's drafts_apply
     * does not refuse a draft that fails validation for live content.
     *
     * @return list<array<string, mixed>>
     */
    private static function publishing(string $runId): array {
        return [
            [
                'tool' => 'drafts_create',
                'args' => ['type' => 'entries', 'id' => '{{fixture.id}}', 'name' => "Smoke {$runId}"],
                'capture' => ['draft.id' => 'draft.id'],
                'assert' => ['draft.id' => 'isInt', 'draft.canonicalId' => '{{fixture.id}}'],
            ],
            [
                'tool' => 'stimmt_drafts_publish',
                'args' => ['id' => '{{draft.id}}'],
                'assert' => [
                    'success' => true,
                    'entry.id' => '{{fixture.id}}',
                    'entry.draftId' => null,
                    'entry.fields.' . self::REQUIRED_FIELD => 'fixture',
                    'affectedSites' => 'notEmpty',
                ],
            ],
        ];
    }

    /**
     * Two blocks onto one draft of the fixture, the second moved ahead of the
     * first, and the draft thrown away, so the fixture's live Matrix field
     * stays empty run after run and the positions asserted here hold.
     *
     * @return list<array<string, mixed>>
     */
    private static function nestedEntries(): array {
        return [
            [
                'tool' => 'stimmt_nested_entries_create',
                'args' => ['owner' => '{{fixture.id}}', 'field' => self::MATRIX_FIELD, 'type' => self::BLOCK_TYPE],
                'capture' => ['blocks.draft' => 'draftElementId', 'block.first' => 'blockId'],
                'assert' => [
                    'success' => true,
                    'state' => 'draft',
                    'ownerId' => '{{fixture.id}}',
                    'blockId' => 'isInt',
                    'draftElementId' => 'isInt',
                    'position' => 1,
                    'affectedSites' => 'notEmpty',
                    'previewUrl' => self::PREVIEW_URL,
                ],
                'secret' => true,
            ],
            [
                // Stacked onto the same draft by passing the draft as owner.
                'tool' => 'stimmt_nested_entries_create',
                'name' => 'stimmt_nested_entries_create.second',
                'args' => ['owner' => '{{blocks.draft}}', 'field' => self::MATRIX_FIELD, 'type' => self::BLOCK_TYPE],
                'capture' => ['block.second' => 'blockId'],
                'assert' => ['success' => true, 'draftElementId' => '{{blocks.draft}}', 'position' => 2],
            ],
            [
                'tool' => 'stimmt_nested_entries_move',
                'args' => ['id' => '{{block.second}}', 'position' => 1],
                // A block's position is shared across sites, so the response
                // has to say which sites the new order landed on.
                'assert' => [
                    'success' => true,
                    'blockId' => '{{block.second}}',
                    'draftElementId' => '{{blocks.draft}}',
                    'position' => 1,
                    'affectedSites' => 'notEmpty',
                ],
            ],
            [
                'tool' => 'drafts_delete',
                'name' => 'drafts_delete.blocks',
                'args' => ['type' => 'entries', 'id' => '{{blocks.draft}}'],
                'assert' => ['deleted' => true],
            ],
        ];
    }

    /**
     * #76: a draft may leave a required field empty; going live may not. The
     * draft is emptied with Craft's own elements_update, which saves a draft
     * without validating it for live content, exactly as the control panel
     * does. Publishing it must be refused naming the field, rather than put an
     * invalid entry live.
     *
     * @return list<array<string, mixed>>
     */
    private static function refusedPublish(string $runId): array {
        return [
            [
                'tool' => 'drafts_create',
                'name' => 'drafts_create.incomplete',
                'args' => ['type' => 'entries', 'id' => '{{fixture.id}}', 'name' => "Smoke {$runId} incomplete"],
                'capture' => ['incomplete.id' => 'draft.id'],
                'assert' => ['draft.id' => 'isInt'],
            ],
            [
                'tool' => 'elements_update',
                'name' => 'elements_update.incomplete',
                'args' => ['type' => 'entries', 'id' => '{{incomplete.id}}', 'fields' => [self::REQUIRED_FIELD => '']],
                'assert' => ['savedAsDraft' => true, 'element.id' => '{{incomplete.id}}'],
            ],
            [
                'tool' => 'stimmt_drafts_publish',
                'name' => 'stimmt_drafts_publish.incomplete',
                'args' => ['id' => '{{incomplete.id}}'],
                'refusal' => self::REQUIRED_FIELD,
            ],
            [
                'tool' => 'drafts_delete',
                'name' => 'drafts_delete.incomplete',
                'args' => ['type' => 'entries', 'id' => '{{incomplete.id}}'],
                'assert' => ['deleted' => true],
            ],
        ];
    }

    /**
     * The baseline install has one site, so the only copy there is a site onto
     * itself, which the tool refuses. On a multi-site install the step asks
     * for the same copy, so the baselines stay comparable; a real cross-site
     * copy would need a second handle and its own baseline.
     *
     * @return list<array<string, mixed>>
     */
    private static function copyToSite(): array {
        return [
            [
                'tool' => 'stimmt_entries_copy_to_site',
                'args' => ['id' => '{{fixture.id}}', 'fromSite' => '{{site.primary}}', 'toSite' => '{{site.primary}}'],
                // A single-site install has nowhere to copy to, so the call
                // exercises the refusal of a copy onto the same site.
                'refusal' => 'copying a site onto itself',
            ],
        ];
    }
}
