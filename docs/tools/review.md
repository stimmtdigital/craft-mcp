# Review Tools

Content review and content health: what a draft changes before it goes live, what a site still has to translate, which links lead to content that is not live, which assets nothing refers to, and who changed what recently.

Craft's own tools list drafts and revisions, and `content_model_audit` checks the content model, but none of them compares a draft with its live entry, finds translation gaps, broken internal links or unused assets, or gives a change feed across sections.

All of these only read. They need the read scope (`mcp:read`) or higher, and are not admin-only. Lite edition. None is dangerous.

| Tool | Scope | Edition |
|------|-------|---------|
| [`stimmt_drafts_diff`](#stimmt_drafts_diff) | read | Lite |
| [`stimmt_entries_untranslated`](#stimmt_entries_untranslated) | read | Lite |
| [`stimmt_links_broken`](#stimmt_links_broken) | read | Lite |
| [`stimmt_assets_unused`](#stimmt_assets_unused) | read | Lite |
| [`stimmt_activity_recent`](#stimmt_activity_recent) | read | Lite |

Every tool runs Craft's own view permissions for the user the client acts as: entries, sections and volumes that user cannot view in the control panel are left out. Each refuses a response larger than `maxResponseBytes` (see [Configuration](../configuration.md)).

---

## stimmt_drafts_diff

Field by field, what a draft changes compared with its live (canonical) entry. Use it before publishing with [`stimmt_drafts_publish`](content.md#stimmt_drafts_publish) or Craft's `drafts_apply`.

Example question: *"What did Anna change in her draft of the About page?"* or *"Show me what this draft changes before I publish it."*

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | A draft element id, or a canonical entry id that has exactly one pending draft. The same id `stimmt_drafts_publish` takes |
| `site` | string | No | Site handle to compare on. Omit for the primary site |

Compared: title, slug, entry type, enabled, enabled for the site, post date, expiry date, structure parent, and every custom field in either entry's field layout.

Returns:

| Key | Meaning |
|-----|---------|
| `site` | The site compared |
| `newEntry` | `true` for a draft of an entry that was never published. There is no live entry then, and every non-empty value reads as `added` |
| `draft` | `id`, `name`, `notes`, `creator` (username), `dateUpdated` |
| `canonical` | `id`, `title`, `status` of the live entry, or `null` for a new entry |
| `changes` | One row per changed attribute or field |
| `unchangedCount` | Attributes and fields that did not change |

A change reads `{field, label, kind, before, after}`, with `kind` one of `added`, `removed` or `changed`. Text longer than 500 characters is cut on either side, and the row carries `truncated: true`. Relations read as natural keys, as in the [payload format](content.md).

Nested content is reported per part, not as one value:

- A Matrix field has `blocks`: one row per block that was `added`, `removed`, `moved` or `changed`, with the block id and type. Added and removed blocks carry their `position` and their non-empty fields; moved blocks carry `from` and `to`; changed blocks carry their own `changes` (and `from`/`to` when they also moved).
- A ContentBlock field has its own `changes`.

```json
{
  "field": "bodyBlocks",
  "label": "Body",
  "kind": "changed",
  "blocks": [
    { "block": "4512", "type": "text", "kind": "changed", "changes": [
      { "field": "heading", "label": "Heading", "kind": "changed", "before": "Our team", "after": "Meet the team" }
    ] },
    { "block": "4530", "type": "quote", "kind": "added", "position": 3, "changes": [ "..." ] }
  ]
}
```

Refused, with the id to use instead: a revision, a provisional draft (unsaved control panel edits; save them as a draft first), and a canonical id with no pending draft or with several (the error lists the draft ids). The user needs permission to view both the draft and the live entry.

---

## stimmt_entries_untranslated

On a multi-site install, entries whose content on a target site is still untranslated.

Example question: *"Which pages on the German site still need translating?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `site` | string | | Required. Target site handle: the site whose translations to check |
| `section` | string | null | Section handle. Omit to scan every section enabled on both sites |
| `sourceSite` | string | null | Site handle to compare against. Omit for the primary site |
| `limit` | int | 50 | How many source-site entries to **scan**, not how many to return. 1 or more |
| `offset` | int | 0 | Entries to skip before scanning, 0 or more |

A value is a gap when the source site has it and the target site:

- leaves it empty (whitespace counts as empty): reason `empty`
- holds the very same value: reason `same-as-source`

Only values the two sites keep apart are checked: custom fields Craft translates per site for these two sites, plus title and slug when the entry type translates them. Values empty on the source are ignored. Relations compare by the elements they point at, Matrix blocks by type and content (not by block id, since each site has its own blocks). Entries of every status are scanned, in id order.

Returns the paginated envelope, plus `site`, `sourceSite` and `scanned`, and `entries`:

```json
{ "id": 42, "title": "About us", "section": "pages", "fields": [
  { "field": "title", "reason": "same-as-source" },
  { "field": "intro", "reason": "empty" }
] }
```

An entry that does not exist on the target site at all reads `missingOnSite: true` with an empty `fields`.

Paging works on entries scanned, so one call never walks the whole install: `total` counts the entries with gaps found **in this window**, `scanned` the entries looked at, and `hasMore` whether to call again at `offset + scanned`.

Refused: a single-site install, the same site as `site` and `sourceSite`, and a section (or install) with no section enabled on both sites.

---

## stimmt_links_broken

Entries whose content links to an entry, asset or category that is not live on the site the link is shown on.

Example question: *"Are there links on the site to pages we have deleted or unpublished?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | null | Section handle. Omit to scan entries from every section |
| `site` | string | null | Site handle the links are checked for. Omit for the primary site |
| `limit` | int | 200 | How many entries to scan, 1 to 1000 |
| `offset` | int | 0 | Entries to skip before the first scanned one, 0 or more |

It reads relation fields (the relations table) and the reference tags that rich text and Link fields store (`{entry:12@1:url}`), in each entry and every nested entry beneath it. Entries of every status are scanned, in id order.

Each finding:

```json
{
  "source": { "id": 42, "title": "About us", "section": "pages", "site": "default", "status": "live", "cpEditUrl": "..." },
  "field": "contentBlocks.body",
  "target": { "type": "entry", "id": 118, "title": "Old team page", "reason": "deleted" }
}
```

`field` is the field handle, prefixed with the Matrix field path when the link sits in a nested entry. `reason` is one of:

| Reason | Meaning |
|--------|---------|
| `deleted` | The target is in the trash |
| `disabled` | Disabled globally or for the site, or archived |
| `expired` | An entry whose expiry date has passed |
| `pending` | An entry whose post date is in the future, or not set |
| `missing` | No element with that id exists on that site |
| `draft-only` | The target is a draft, not a published element |

Findings are grouped by source entry, then by field. A target the user may not view shows its `type`, `id` and `reason` but no `title`.

Returns the paginated envelope with `broken`, plus `site`, `scanned` and `unchecked`. The window is over entries, not findings: `total` and `hasMore` count entries, so ask again at `offset + limit` to scan the rest. `unchecked` counts reference tags that name a target by slug (`{entry:news/launch}`) rather than id; those are not checked.

Bounds: at most 1000 entries per call, with up to 5000 nested entries and 50000 relations between them. Past either nested or relation bound the call is refused (ask again with a lower `limit` or one section) rather than answering with fewer findings than there are. Nested entries are followed up to 8 levels deep.

---

## stimmt_assets_unused

Assets nothing in Craft refers to, largest first.

Example question: *"Which images in the uploads volume are not used anywhere, and how much space do they take?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `volume` | string | null | Volume handle. Omit for every volume the user may view |
| `olderThan` | int | 0 | Only assets created at least this many days ago. 0 for any age |
| `limit` | int | 100 | Assets to return, 1 to 500 |
| `offset` | int | 0 | Rows to skip, 0 or more |

"Unused" means not referenced in Craft: no relation to the asset from any field (Link fields included), no `{asset:ID}` reference tag in any content, and not a user photo. Canonical elements and drafts count as references; revisions and trashed elements do not.

> An unused asset is not proof the file is unused. Templates, front-end code, other sites or emails may hardcode its URL. Treat the result as a list to review, never a list to delete, and confirm with a person before removing anything. The tool itself deletes nothing.

Returns the paginated envelope, plus `volume` and `totalBytes` (the size of **every** matching asset, not only this page), and `assets`, each `id`, `filename`, `path`, `volume`, `size` (bytes), `dateCreated`, `cpEditUrl`.

Bounds: the reference-tag check reads every content row that mentions an asset tag. When more than 20000 such rows, or more than 10000 distinct tagged assets, exist on the install, the call is refused rather than answered from a partial scan, which would list used assets as unused.

---

## stimmt_activity_recent

A newest-first feed of entry changes across every section: who saved or deleted which entry, and when.

Example question: *"What changed on the site this week, and who did it?"* or *"What has Jan edited since Monday?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `since` | string | 7 days ago | Earliest change to include: any date Craft parses, such as `"2026-11-01"`, `"2026-11-01T09:00:00+01:00"` or `"-3 days"` |
| `section` | string | null | Section handle. Omit for every section the user may view |
| `author` | string | null | Username or email of the user whose saves to list. Deletions are left out when this is set |
| `limit` | int | 50 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip, 0 or more |

`offset + limit` may not exceed 500; narrow with a later `since`, a section or an author instead.

Built from two sources in Craft's own history:

- Revisions: one per save of a live entry. `kind` is `created` for revision 1 and `updated` after it. Revision 1 can also be the first save after versioning was switched on, which is why the row carries `revisionNum` too.
- Soft-deleted entries: `kind` is `deleted`, and `changedBy` is `null` because Craft does not record who deleted an entry.

Returns `since` (ISO 8601) and the paginated envelope with `changes`, each:

| Key | Meaning |
|-----|---------|
| `entryId`, `title`, `section`, `site` | The entry. `site` is `null` when its site was deleted |
| `date` | ISO 8601 |
| `kind` | `created`, `updated` or `deleted` |
| `revisionNum` | `null` for a deletion |
| `changedBy` | `{id, username}`. `username` is `null` when the account was deleted since; the whole value is `null` for deletions |
| `notes` | Revision notes, or `null` |
| `cpEditUrl` | The revision's control panel URL. `null` for a deletion: restore it from the section's index with the **Trashed** status filter |

Not in the feed: sections with versioning switched off, unsaved drafts, nested Matrix entries (their owner's save is the row), and revisions Craft has already pruned (`maxRevisions`). An `author` that matches no user is refused rather than answered with an empty feed. The editor's username is shown to anyone who may view the entry, as the control panel shows it.
