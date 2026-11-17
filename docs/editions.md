# Editions

The plugin comes in two editions. Switch editions in the control panel under **Settings → Plugins**.

**Lite** is the free toolkit for investigating an install: what is happening technically and why. **Pro** is the edition for managing and maintaining sites: reviewing content before and after it goes live, reporting on a site, and the content tools Craft's own MCP server does not have.

## Lite (free)

- install health and operations: `stimmt_health`, `stimmt_errors_summary`, `stimmt_queue_failures`, `stimmt_requests_slow`, `stimmt_dependencies_audit`
- the developer tools: database, logs, config, routes, console commands, debugging, GraphQL, backups and `stimmt_tinker`
- `stimmt_entries_count`, `stimmt_refs_resolve`, `stimmt_entries_preview_url`
- `stimmt_categories_list` and `stimmt_globals_list`
- the Commerce reads, when Craft Commerce is installed
- the `stimmt://guides/tools` resource

## Pro

Everything in Lite, plus:

| Tool | What it does |
|------|--------------|
| `stimmt_drafts_diff` | Field by field, what a draft changes compared with its live entry |
| `stimmt_drafts_publish` | Publish a draft after validating it as live content, the way the control panel does. Craft's own `drafts_apply` does not, so a draft missing a required field can go live through it |
| `stimmt_entries_untranslated` | Entries whose content on a site is empty or still the same as the source site |
| `stimmt_links_broken` | Links to entries, assets or categories that are deleted or not live |
| `stimmt_assets_unused` | Assets nothing in Craft refers to |
| `stimmt_activity_recent` | Who changed which entry, and when, across every section |
| `stimmt_maintenance_report` | Health, errors and content changes over a period, for a maintenance report |
| `stimmt_redirects_404s` | Retour's most frequent 404s, with a suggested redirect target (Retour 5) |
| `stimmt_seo_gaps` | Entries with missing or too long SEO metadata, as SEOmatic resolves it (SEOmatic 5) |
| `stimmt_forms_failures` | Formie submissions whose integrations or notifications failed (Formie 4) |
| `stimmt_entries_copy_to_site` | Copy an entry's per-site field values to another site, as a draft |
| `stimmt_nested_entries_create` | Add one Matrix block without resending the field's other blocks |
| `stimmt_nested_entries_move` | Move one Matrix block to a new position |

See [Review tools](tools/review.md), [Plugin integrations](tools/integrations.md), [Operations](tools/operations.md) and [Content Tools](tools/content.md) for their parameters.

## How Lite behaves

On Lite the Pro tools are not offered at all: a client never sees a tool it cannot call. The instructions the plugin adds to Craft's MCP server mention what Pro adds; on Pro they describe each of these tools, and tell the agent to publish with `stimmt_drafts_publish` rather than `drafts_apply`.

`php craft mcp/check` and the plugin's settings screen report the current edition.
