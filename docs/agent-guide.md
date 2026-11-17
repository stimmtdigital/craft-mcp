# Using the stimmt_ tools

The `stimmt_` tools come from the Craft MCP plugin. They add to Craft's own tools; they do not replace them. Use Craft's tools for listing, reading, creating, updating and deleting elements (`elements_*`, `drafts_*`, `revisions_*`, `configuration_*`), and the `stimmt_` tools for what those leave out.

Which tools you have depends on the scope you were granted, whether your user is an admin, and the plugin's edition. If a tool named here is not in your tool list, you do not have it; say so instead of working around it.

## Natural keys to ids

Craft's `elements_*` tools take element ids. People give you slugs, filenames and usernames. Resolve them first with `stimmt_refs_resolve`:

| type | key |
|------|-----|
| `entry` | `{"section": "news", "slug": "launch"}` |
| `asset` | `{"volume": "images", "filename": "hero.jpg"}`, plus `"path"` for a file in a folder |
| `category`, `tag` | `{"group": "topics", "slug": "craft"}` |
| `user` | `{"username": "anna"}` |
| `globalSet` | `{"handle": "footer"}` |

Pass `site` when slugs differ per site. A key that matches nothing or several elements is refused; do not guess an id instead.

## Counting

To answer "how many" or "how many per X", call `stimmt_entries_count` once instead of paging through a list. `groupBy` takes an attribute (`status`, `type`, `section`, `site`, `author`), a date bucket such as `"month:dateUpdated"`, or a field handle. Counts include every status unless you pass `status`, and leave out Matrix blocks unless you pass `includeNested: true`.

## Let a person review before publishing

Craft's write tools change live content unless you ask for a draft. Write as a draft: for a new entry, `elements_create` with `asUnpublishedDraft`; for an existing one, `drafts_create`, then `elements_update` on the draft's id. Then give the person a link to check it:

- `stimmt_entries_preview_url` with the draft's id returns a link that renders the draft without logging in. It expires. Sections without URLs have no preview.
- The `stimmt_` write tools also return `cpEditUrl`, the control panel link, and their own `previewUrl`.

Do not publish unless the person asked you to.

## Show what a draft changes

Before publishing a draft, or when a person asks what a draft changes, call `stimmt_drafts_diff` with the draft's id (or the canonical id when it has one pending draft) and show the person the changes in plain words. It lists changed attributes and fields as `added`, `removed` or `changed`, Matrix changes per block, and cuts text over 500 characters (`truncated: true`); say so when you quote a cut value. `newEntry: true` means the entry was never published, so everything reads as added. Publishing stays the person's decision.

## Publishing

Publish with `stimmt_drafts_publish` (Pro), not `drafts_apply`. It validates the draft as live content first, as the control panel does, so a draft with an empty required field is not published. On failure it returns `success: false` and `errors` per field: fix those fields on the draft, then publish again.

Pass the draft's element id. A canonical entry id works only when it has exactly one pending draft; with several, the error lists their ids.

## Matrix blocks

Do not rewrite a whole Matrix field to change one block: any block left out of the new value is deleted.

- Add one block: `stimmt_nested_entries_create` with `owner`, `field`, `type`, and `fields` as a JSON **string**. It lands on a draft of the owner. Pass the returned `draftElementId` as `owner` to add more blocks to the same draft, then publish that draft.
- Reorder: `stimmt_nested_entries_move` with the block's canonical id and a 1-based `position`.
- Edit or delete one existing block: it is an entry with its own id, so use Craft's `elements_update` or `elements_delete` on that id.

After publishing an owner draft, read the owner again for the blocks' final ids.

In `fields` payloads, relations are lists of natural keys (as above), new nested blocks are keyed `new1`, `new2`, … with `type` and `fields`, and Date fields with a time zone take `{"date": "Y-m-d H:i:s", "timezone": "Europe/Amsterdam"}`. Always read `warnings` in the response: an unresolvable key is reported there, not guessed.

## Multi-site

`stimmt_entries_copy_to_site` (Pro) copies the fields an entry keeps per site from `fromSite` to `toSite`, as a draft. It does not translate, and it never copies title or slug. An empty `copiedFields` means the entry type has no per-site fields.

## Content health

These only read, and list only what the user may view.

- **Translations**: `stimmt_entries_untranslated` with the target `site` (and `sourceSite` if it is not the primary site). `limit` is how many entries are scanned, not returned, so an empty `entries` with `hasMore: true` means "nothing in this window", not "nothing left": call again at `offset + scanned`. To fill a gap, `stimmt_entries_copy_to_site` (Pro) copies values as a draft; it does not translate.
- **Broken links**: `stimmt_links_broken`. Pages through entries, not findings, so keep calling at `offset + limit` while `hasMore` is true. Each finding names the source entry, the field and the reason (`deleted`, `disabled`, `expired`, `pending`, `missing`, `draft-only`). Fix the link or the target with the person; do not unpublish or delete anything yourself.
- **Unused assets**: `stimmt_assets_unused`. "Unused" means not referenced in Craft; templates, front-end code or emails may still use the file by URL. Never delete these assets, and never suggest deleting them in bulk, without the person confirming each one or the list. Report `totalBytes` as the size of every match.
- **Recent changes**: `stimmt_activity_recent` for "what changed and who did it". Deletions have no `changedBy`, because Craft does not record who deleted an entry; say "deleted (by unknown)" rather than guessing. With `author` set, deletions are left out.

## Plugin integrations

These exist only when the plugin is installed and enabled: if one is not in your tool list, the site does not run that plugin (or that version).

- `stimmt_redirects_404s` (Retour): the most-hit missing URLs. `suggestion` is a guess by slug similarity; present it as one, and let the person decide on any redirect. `hits` is a lifetime count.
- `stimmt_seo_gaps` (SEOmatic): entries with missing or too long SEO metadata. `limit` is entries scanned (at most 50). A `flag` severity (noindex on a live entry) may be deliberate; ask rather than call it an error.
- `stimmt_forms_failures` (Formie, admins only): failed CRM, webhook and email deliveries per submission. It never returns submitted values; do not try to get them another way. Retrying happens in Formie's control panel.

## Categories and global sets

Craft's tools do not cover them. Use `stimmt_categories_list` (filter by `group`) and `stimmt_globals_list` (admins only).

## Diagnosing the install

When something about the install is unclear, look instead of guessing. Admin users have:

- `stimmt_health` first, whenever the question is "is something wrong" or the cause is unknown. It checks the MCP setup, environment, migrations and cached updates, project config, the queue, storage and the last 24 hours of errors, errors first, each problem with a `fix`. Act on its `fix` lines and follow the tool names they mention
- `stimmt_errors_summary` next, for what keeps failing: errors grouped by cause with counts. When `complete` is false, the counts are a floor; say "at least". Then `stimmt_errors_last` and `stimmt_logs_read` (filter by `source`, `level`, `pattern`) for the raw entries
- `stimmt_queue_failures` for failed jobs and their errors. It only reads: fix the cause first, then retry with Craft's `queue_retry_all` and `queue_run`, and only when the person agrees
- `stimmt_requests_slow` for slow pages and repeated queries (N+1). It needs Yii debug data recorded on this server; when there is none, pass on its instructions for turning recording on rather than guessing
- `stimmt_dependencies_audit` for known security advisories in installed Composer packages. It sends the package names to packagist.org. Packages under `uncovered` were not checked; never report them as safe
- `stimmt_maintenance_report` for a periodic report. It returns raw figures: summarise them for a person (health problems and what to do, top errors, content changes per section and editor) instead of pasting the output
- `stimmt_config_get` for config values (credentials are redacted), `stimmt_environment_get` for environment and PHP settings
- `stimmt_db_schema`, `stimmt_db_table_counts`, `stimmt_db_query` (SELECT only) and `stimmt_db_explain` for the database; prefer content tools for content
- `stimmt_routes_list`, `stimmt_event_handlers_list`, `stimmt_deprecations_list`, `stimmt_console_commands_list` (stdio only)
- `stimmt_graphql_schemas_list`, `stimmt_graphql_schema_get` and `stimmt_graphql_query` to read anything the GraphQL API exposes
- `stimmt_tinker` to run PHP, as a last resort when no tool can express the job. Keep it read-only; write content through the content tools so drafts and validation apply

`stimmt_tinker`, `stimmt_db_query`, `stimmt_graphql_execute` and `stimmt_backups_create` are switched off in production by default.
