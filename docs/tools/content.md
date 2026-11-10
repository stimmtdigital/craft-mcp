# Content Tools

These tools sit next to Craft's own content tools (`elements_*`, `drafts_*`, `revisions_*`). Craft lists, reads, creates, updates and deletes elements; the plugin adds what those tools leave out: grouped counts, natural keys to element ids, preview links, validated publishing, copying between sites, single Matrix-block writes, and reads for categories and global sets.

| Tool | Scope | Edition |
|------|-------|---------|
| [`stimmt_entries_count`](#stimmt_entries_count) | read | Lite |
| [`stimmt_refs_resolve`](#stimmt_refs_resolve) | read | Lite |
| [`stimmt_entries_preview_url`](#stimmt_entries_preview_url) | read | Lite |
| [`stimmt_categories_list`](#stimmt_categories_list) | read | Lite |
| [`stimmt_globals_list`](#stimmt_globals_list) | admin | Lite |
| [`stimmt_drafts_publish`](#stimmt_drafts_publish) | write | Pro |
| [`stimmt_entries_copy_to_site`](#stimmt_entries_copy_to_site) | write | Pro |
| [`stimmt_nested_entries_create`](#stimmt_nested_entries_create) | write | Pro |
| [`stimmt_nested_entries_move`](#stimmt_nested_entries_move) | write | Pro |

Every tool also runs Craft's own permission checks for the user the client acts as: a user who cannot view or save an entry in the control panel cannot do it here either.

---

## stimmt_entries_count

Count entries, optionally grouped. One call answers "how many per X" without listing anything.

Counts include every status unless you pass `status`. Nested Matrix blocks are left out unless `includeNested` is true (in Craft 5 a Matrix block is an entry too, so an unscoped count would otherwise include them).

| Name | Type | Description |
|------|------|-------------|
| `section` | string | Section handle. Omit to count across every section |
| `includeNested` | bool | Count nested Matrix blocks too. Default `false` |
| `type` | string | Entry type handle. A type that belongs to no section (a Matrix block type) is refused unless `includeNested` is true |
| `status` | string | `live`, `pending`, `expired`, `disabled`, or `any` |
| `site` | string | Site handle. Omit for the primary site |
| `search` | string | Craft search query |
| `filters` | object | Field-value filters: `{fieldHandle: value}`. Values are scalars, `":empty:"`, `":notempty:"`, or a natural key for relation fields |
| `relatedTo` | object | Only entries related to this element, as a natural key |
| `author` | string | Author username or email address |
| `updatedAfter` / `updatedBefore` | string | Bounds on `dateUpdated` (after is inclusive, before is exclusive). Any date Craft parses |
| `createdAfter` / `createdBefore` | string | Bounds on `dateCreated`, same rules |
| `groupBy` | string | An attribute (`status`, `type`, `section`, `site`, `author`), a date bucket (`day`, `week`, `month` or `year` with `dateCreated`, `dateUpdated` or `postDate`, written as `"month:dateUpdated"`), or a field handle. Omit for a plain total |

Grouping by a relation field buckets by the related element's title; empty values go under `"(empty)"`.

```json
{ "total": 245, "groupBy": null }
```

With `groupBy`:

```json
{
  "total": 245,
  "buckets": [
    { "key": "2026-01", "count": 18 },
    { "key": "2026-02", "count": 32 }
  ],
  "groupBy": "month:dateUpdated"
}
```

`buckets` is capped at 200. When there are more distinct values, the largest 200 come back and `truncated` is `true`.

---

## stimmt_refs_resolve

Turn a natural key into the element id that Craft's `elements_*` tools take.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | Yes | `entry`, `asset`, `category`, `tag`, `user` or `globalSet` |
| `key` | object | Yes | The natural key (see [Natural keys](#natural-keys)) |
| `site` | string | No | Site handle, for types whose slugs differ per site. Omit for the primary site |

```json
{ "type": "entry", "key": { "section": "news", "slug": "launch" }, "id": 123 }
```

A key that matches nothing, or matches several elements, is refused with an error that says which, and what a key of that type consists of.

---

## stimmt_entries_preview_url

A link that renders an entry or draft as saved, without logging in, so a person can review it before it is published.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | Entry or draft element id. Pass the draft's id to preview unpublished changes |
| `site` | string | No | Site handle. Omit for the primary site |

```json
{ "id": 892, "previewUrl": "https://example.com/about?token=…" }
```

Entries in a section without URLs on that site are refused. The link expires after `previewTokenDuration` seconds, or Craft's `defaultTokenDuration` when that setting is not set. See [Configuration](../configuration.md).

---

## stimmt_categories_list

List categories. Craft's own tools do not cover categories.

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `group` | string | null | Category group handle. Omit for every group |
| `limit` | int | 100 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip |

Each category carries `id`, `title`, `slug`, `level`, `groupId`, `groupHandle` and `url`. The response uses the paginated envelope described in the [Tools Reference](README.md#response-shape); `total` counts only the categories this user may see.

---

## stimmt_globals_list

List every global set with its field values. Admin users only.

No parameters. Each set carries `id`, `handle`, `name` and `fields`. Field values are in the [payload format](#payload-format): relations come back as natural keys.

---

## stimmt_drafts_publish

> Pro edition.

Publish an entry: apply a draft to its canonical entry, or enable a disabled live entry.

Use this rather than Craft's `drafts_apply`. Craft's tool checks only the essentials, so a draft missing a required field can be published. This tool validates the draft as live content first, the way the control panel does before applying a draft (on a multi-site install, across sites). When validation fails, nothing is published.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | A draft's element id, or a canonical entry id that has exactly one pending draft (or none, and is only disabled) |
| `site` | string | No | Site to locate the entry by and to report the result in. Publishing is not per site: the draft is applied on every site it exists on |

Success:

```json
{
  "success": true,
  "entry": { "...": "the entry after publishing, in the payload format" },
  "affectedSites": ["default", "german"]
}
```

Validation failure:

```json
{
  "success": false,
  "action": "publish",
  "draftElementId": 790,
  "message": "Not published: the draft does not pass validation for live content. …",
  "errors": { "summary": ["Summary cannot be blank."] }
}
```

A canonical id with more than one pending draft is refused with the draft ids to choose from. A canonical entry with no pending draft that is already enabled is refused with "nothing to publish". Enabling a disabled entry is validated the same way as applying a draft.

---

## stimmt_entries_copy_to_site

> Pro edition.

Copy the field values an entry keeps separately per site from one site to another, as a draft on the target site. It copies values; it does not translate them.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | Entry id (the same on every site) |
| `fromSite` | string | Yes | Site handle to read from |
| `toSite` | string | Yes | Site handle to write the draft on. The entry must already exist there, which means its section is enabled for that site |

Title and slug are never copied, so a translated title survives. Fields Craft does not treat as translatable are skipped too: they already hold one shared value on every site.

The response is a write result (see [Write responses](#write-responses)) plus `copiedFields`, the handles actually copied. An empty `copiedFields` means the entry type keeps no field per site; titles and slugs may still differ.

Only canonical entries are accepted. A draft or revision id is refused with the canonical id to call with instead.

---

## stimmt_nested_entries_create

> Pro edition.

Add one block to a Matrix field without resending the field's other blocks. Writing the whole field through `elements_update` replaces its value, so any block left out of that payload is deleted; this tool touches only the new block.

In draft mode (the default) the block lands on a draft **of the owner**, the same way a control panel edit does. Sibling blocks and the live page stay untouched until the owner draft is published with `stimmt_drafts_publish`. Pass the returned `draftElementId` as `owner` to stack more blocks onto the same draft.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `owner` | int | Yes | Id of the entry that owns the Matrix field: a canonical entry, or a draft of one |
| `field` | string | Yes | Matrix field handle on the owner's field layout |
| `type` | string | Yes | Entry type handle the Matrix field allows |
| `title` | string | No | Block title, for block types with a title field |
| `fields` | string | No | The block's field values as a JSON-encoded **string**, in the [payload format](#payload-format) |
| `site` | string | No | Site to write on and report in. The block is added on every site the owner exists on; only translatable field values differ per site |
| `mode` | string | No | `"draft"` (default) or `"live"`. Live adds the block to the canonical owner directly |
| `position` | int | No | 1-based slot among the existing blocks. Omit to append; past the end clamps to the last slot |

```json
{
  "success": true,
  "action": "created",
  "blockId": 512,
  "ownerId": 123,
  "draftId": 14,
  "draftElementId": 890,
  "state": "draft",
  "position": 3,
  "affectedSites": ["default"],
  "cpEditUrl": "https://example.com/admin/entries/pages/890-about",
  "previewUrl": "https://example.com/about?token=…",
  "warnings": [],
  "errors": []
}
```

- `blockId` is the new block's own entry id; `ownerId` is the canonical owner.
- `draftElementId` is the owner draft: publish it, preview it, or pass it as `owner` again. Both draft keys are `null` in live mode, where `state` is `"live"`.
- `position` is the slot actually taken.
- After publishing, read the owner again: Craft copies a draft-owned block onto the canonical entry when the draft is applied, so the block's final id comes from the published owner.

An unknown `field` is refused with the owner's Matrix field handles; an unknown `type` with the field's allowed types. Revisions cannot receive blocks. If the block saves but cannot be placed, the call removes it again rather than leaving a copy behind for a retry to duplicate.

---

## stimmt_nested_entries_move

> Pro edition.

Move a Matrix block to a new 1-based position within its field.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | The block's own entry id, the canonical one |
| `position` | int | Yes | Target slot. Past the end clamps to the last slot |
| `site` | string | No | Site to locate the block by. Order is shared across sites, so the new order applies on every site |
| `mode` | string | No | `"draft"` (default) stages the reorder on a draft of the owner; `"live"` reorders the canonical owner |

The response has the same shape as `stimmt_nested_entries_create`, with `action` set to `"moved"`.

Refused, with a message that says why: ids that are not nested entries, draft or revision copies of a block (the message names the canonical block id), blocks in fields that are not Matrix fields, and owners that are revisions.

---

## Payload format

`stimmt_nested_entries_create` takes its `fields` in this format, and `stimmt_globals_list` and `stimmt_drafts_publish` return field values in it. Field translators registered by other plugins extend it (see [Extending](../extending.md)).

### Natural keys

Relations name elements by keys a person knows rather than by numeric id:

| Target | Key | Example |
|--------|-----|---------|
| Entries | `{section, slug}` | `{"section": "pages", "slug": "about"}` |
| Assets | `{volume, path?, filename}` | `{"volume": "images", "filename": "hero.jpg"}` |
| Categories, tags | `{group, slug}` | `{"group": "topics", "slug": "craft"}` |
| Users | `{username}` | `{"username": "anna"}` |
| Global sets | `{handle}` | `{"handle": "footer"}` |

A relation field takes a list of these. A numeric id is accepted wherever a key is. Reads always return keys. Keys resolve on the `site` the call writes on.

A key that resolves to nothing becomes a warning on an otherwise successful write: nothing is guessed and nothing is silently dropped. Read `warnings` on every response.

### Matrix values

A Matrix value is an object keyed by block. Use `new1`, `new2`, … for blocks that do not exist yet. Each block has the entry type handle as `type`, an optional `title` and `enabled`, and a `fields` map:

```json
{
  "contentBuilder": {
    "new1": {
      "type": "contentBlock",
      "enabled": true,
      "fields": {
        "body": "<h2>Hello</h2><p>Rich text is an HTML string.</p>"
      }
    }
  }
}
```

This shape applies when a block passed to `stimmt_nested_entries_create` itself contains a Matrix field. Field values inside blocks follow the same rules, natural keys included.

Reads add a 1-based `position` to each block; it is the only reliable statement of the order, because many JSON clients reorder integer-like keys. On a write, blocks with a `position` are saved in that order and blocks without one follow in the order given. `position` is removed before Craft sees the value.

### Date fields with a time zone

A Date field with **Show time zone** on is read and written as:

```json
"startDate": { "date": "2026-11-12 10:00:00", "timezone": "Australia/Perth" }
```

`date` is the local time in that zone, and `timezone` is an IANA zone name. Writing back what was read changes nothing. A plain date-time string is still accepted, but it is stored in the system time zone and the response carries a warning saying so. An unknown zone name is refused. Date fields without a zone take and return a plain date string.

### Write responses

Writes return:

| Key | Meaning |
|-----|---------|
| `success` | Whether the write was saved |
| `action` | What happened, for example `created`, `updated` or `moved` |
| `elementId` / `blockId` | The element written |
| `draftId`, `draftElementId` | The draft the change is on; `null` for live writes |
| `state` | `draft` or `live` |
| `cpEditUrl` | Control panel link for a person to review |
| `previewUrl` | Preview link without login, or `null` for sections without URLs |
| `warnings` | Natural keys that could not be resolved, plain dates stored in the system zone, and similar |
| `errors` | Validation errors per field when `success` is `false` |
