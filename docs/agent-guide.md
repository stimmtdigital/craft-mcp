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

## Categories and global sets

Craft's tools do not cover them. Use `stimmt_categories_list` (filter by `group`) and `stimmt_globals_list` (admins only).

## Diagnosing the install

When something about the install is unclear, look instead of guessing. Admin users have:

- `stimmt_errors_last` and `stimmt_logs_read` (filter by `source`, `level`, `pattern`) for what went wrong
- `stimmt_config_get` for config values (credentials are redacted), `stimmt_environment_get` for environment and PHP settings
- `stimmt_db_schema`, `stimmt_db_table_counts`, `stimmt_db_query` (SELECT only) and `stimmt_db_explain` for the database; prefer content tools for content
- `stimmt_routes_list`, `stimmt_event_handlers_list`, `stimmt_deprecations_list`, `stimmt_console_commands_list` (stdio only)
- `stimmt_graphql_schemas_list`, `stimmt_graphql_schema_get` and `stimmt_graphql_query` to read anything the GraphQL API exposes
- `stimmt_tinker` to run PHP, as a last resort when no tool can express the job. Keep it read-only; write content through the content tools so drafts and validation apply

`stimmt_tinker`, `stimmt_db_query`, `stimmt_graphql_execute` and `stimmt_backups_create` are switched off in production by default.
