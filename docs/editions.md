# Editions

The plugin comes in two editions. Switch editions in the control panel under **Settings → Plugins**.

## Lite (free)

Every tool except the four below:

- the developer tools: database, logs, config, routes, console commands, debugging, GraphQL, backups and `stimmt_tinker`
- `stimmt_entries_count`, `stimmt_refs_resolve`, `stimmt_entries_preview_url`
- `stimmt_categories_list` and `stimmt_globals_list`
- the Commerce reads, when Craft Commerce is installed
- the `stimmt://guides/tools` resource

## Pro

Pro adds four content tools:

| Tool | What it does |
|------|--------------|
| `stimmt_drafts_publish` | Publish a draft after validating it as live content, the way the control panel does. Craft's own `drafts_apply` does not, so a draft missing a required field can go live through it |
| `stimmt_entries_copy_to_site` | Copy an entry's per-site field values to another site, as a draft |
| `stimmt_nested_entries_create` | Add one Matrix block without resending the field's other blocks |
| `stimmt_nested_entries_move` | Move one Matrix block to a new position |

See [Content Tools](tools/content.md) for their parameters.

## How Lite behaves

On Lite the Pro tools are not offered at all: a client never sees a tool it cannot call. The instructions the plugin adds to Craft's MCP server mention that Pro adds these tools; on Pro they tell the agent to publish with `stimmt_drafts_publish` rather than `drafts_apply`.

`php craft mcp/check` and the plugin's settings screen report the current edition.

## Requiring an edition in your own tools

Your own tools register with Craft directly (see [Extending](extending.md)), so the plugin's editions do not apply to them.
