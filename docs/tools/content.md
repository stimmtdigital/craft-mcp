# Content Tools

Content tools let AI assistants query and manage the core content types in your Craft installation: entries, assets, categories, users, and global sets.

## Entries

Entries are the primary content type in Craft CMS. Reads and writes share one payload format: `get_entry` returns exactly what `create_entry` and `update_entry` accept as `fields`. Relations resolve to natural keys instead of numeric ids (`{"section": "pages", "slug": "about"}` for entries, `{"volume": "images", "filename": "hero.jpg"}` for assets), and Matrix blocks are objects keyed by block id with a `type` entry-type handle naming the block type. Writes are draft-first by default, and the eight tools that write entry content require the Pro edition; every read on this page is available on both. See the [Content Writing guide](../content-writing.md) for the full format and workflow; this page covers each tool's parameters and response shape.

### list_entries

List entries. Filter by section, type, status, site handle, full-text search, field values (with `:empty:`/`:notempty:` and natural keys for relations), `relatedTo`, author, and date ranges. Returns entries in the payload format described above.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | null | Filter by section handle (e.g., "news", "blog") |
| `type` | string | null | Filter by entry type handle |
| `status` | string | null | Filter by status: "live", "pending", "expired", "disabled", or "any" |
| `site` | string | null | Site handle to query and read fields from (defaults to the primary site) |
| `search` | string | null | Full-text search term |
| `filters` | object | null | Field-value filters: `{fieldHandle: value}`. Values are scalars, `":empty:"`, `":notempty:"`, or a natural key object for relation fields (e.g. `{"group": "...", "slug": "..."}`) |
| `relatedTo` | object | null | Only entries related to this element, as a natural key: `{"section", "slug"}`, `{"volume", "filename"}`, `{"group", "slug"}`, or `{"username"}` |
| `author` | string | null | Filter by the author's username or email |
| `updatedAfter` | string | null | ISO date/datetime; only entries updated on or after this |
| `updatedBefore` | string | null | ISO date/datetime; only entries updated before this |
| `createdAfter` | string | null | ISO date/datetime; only entries created on or after this |
| `createdBefore` | string | null | ISO date/datetime; only entries created before this |
| `fields` | array of string | null | Projection: return only these attributes and field handles per entry (`id` and `title` always included) instead of the full payload. Ideal for scanning many entries |
| `limit` | int | 20 | Maximum number of entries to return |
| `offset` | int | 0 | Number of entries to skip (for pagination) |

**Examples:**

```
# Get the 10 most recent news entries
list_entries section="news" limit=10

# Get all live blog posts of a specific type
list_entries section="blog" type="article" status="live"

# Full-text search within a section
list_entries section="news" search="product launch"

# Paginate through results
list_entries section="products" limit=20 offset=40

# Field filters: a scalar, ":empty:", and a natural key, combined
list_entries section="news" filters={"featured": true, "summary": ":empty:", "category": {"group": "topics", "slug": "company"}}

# Only entries related to a specific asset
list_entries relatedTo={"volume": "images", "filename": "hero.jpg"}

# Entries by a specific author, updated since the start of the year
list_entries author="anna@site.nl" updatedAfter="2024-01-01"

# Slim rows for scanning many entries: id, title, plus these attributes/fields only
list_entries section="news" fields=["slug", "status", "dateUpdated"] limit=200
```

**Response:**

```json
{
  "count": 10,
  "total": 45,
  "limit": 10,
  "offset": 0,
  "hasMore": true,
  "entries": [
    {
      "id": 123,
      "canonicalId": 123,
      "title": "My Entry",
      "slug": "my-entry",
      "status": "live",
      "state": "live",
      "draftId": null,
      "siteId": 1,
      "siteHandle": "default",
      "dateCreated": "2024-01-15 10:30:00",
      "dateUpdated": "2024-01-15 14:22:00",
      "url": "https://example.com/news/my-entry",
      "cpEditUrl": "https://example.com/admin/entries/news/123-my-entry",
      "sectionHandle": "news",
      "typeHandle": "default",
      "authorId": 1,
      "postDate": "2024-01-15 10:30:00",
      "expiryDate": null,
      "fields": {
        "summary": "A brief description",
        "featuredImage": [{ "volume": "images", "filename": "hero.jpg" }]
      },
      "warnings": []
    }
  ]
}
```

`status` is the element's publish status (`live`, `pending`, `expired`, `disabled`); `state` is `draft` or `live`, i.e. whether this record is a draft awaiting `publish_entry` or the canonical version. `fields` holds custom field values in the payload format: relation fields as lists of natural keys, Matrix fields as block objects keyed by id. `warnings` lists any natural key in this entry's fields that failed to resolve to an element; see [Content Writing: Structured Feedback](../content-writing.md#structured-feedback).

When `fields` (the projection parameter) is given, each row is slimmed down to `id`, `title`, the requested attributes, and a `fields` object holding only the requested field handles, instead of the full payload above.

---

### count_entries

Count entries, optionally grouped: by attribute (`status`, `type`, `section`, `site`, `author`), by date bucket (`"month:dateUpdated"`, any of `day`/`week`/`month`/`year` with `dateCreated`/`dateUpdated`/`postDate`), or by a field handle (relation fields bucket by related title; empty values fall under `"(empty)"`). Takes the same filters as `list_entries`. Counts include every status by default, exactly as `list_entries` lists them; pass status to narrow either. One call answers "how many per X" without listing anything.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | null | Filter by section handle |
| `type` | string | null | Filter by entry type handle |
| `status` | string | null | Filter by status: "live", "pending", "expired", "disabled", or "any" |
| `site` | string | null | Site handle to query (defaults to the primary site) |
| `search` | string | null | Full-text search term |
| `filters` | object | null | Field-value filters: `{fieldHandle: value}`. Values are scalars, `":empty:"`, `":notempty:"`, or a natural key object for relation fields |
| `relatedTo` | object | null | Only entries related to this element, as a natural key |
| `author` | string | null | Filter by the author's username or email |
| `updatedAfter` | string | null | ISO date/datetime; only entries updated on or after this |
| `updatedBefore` | string | null | ISO date/datetime; only entries updated before this |
| `createdAfter` | string | null | ISO date/datetime; only entries created on or after this |
| `createdBefore` | string | null | ISO date/datetime; only entries created before this |
| `groupBy` | string | null | An attribute (`status`, `type`, `section`, `site`, `author`), a date bucket (`granularity:dateAttribute`, e.g. `"month:dateUpdated"`), or a field handle. Omit for a plain total |
| `output` | string | "structured" | "structured" for the JSON payload, "text" for an aligned breakdown table. See [Text Output](README.md#text-output) |

**Examples:**

```
# Plain total
count_entries section="news"

# Per-status breakdown
count_entries section="news" groupBy="status"

# Entries per month, by last update
count_entries section="news" groupBy="month:dateUpdated"

# Per-value breakdown on a relation field
count_entries section="news" groupBy="category"
```

**Response:**

```json
{
  "success": true,
  "total": 245,
  "groupBy": null
}
```

With `groupBy`, a `buckets` list is added, one entry per distinct value, sorted by count descending (chronologically for date buckets):

```json
{
  "success": true,
  "total": 245,
  "buckets": [
    { "key": "2023-12", "count": 18 },
    { "key": "2024-01", "count": 32 }
  ],
  "groupBy": "month:dateUpdated"
}
```

The same breakdown with `output="text"`:

```
count_entries section="news" groupBy="month:dateUpdated" output="text"

success: true
total:   245
buckets:
  key      count
  -------  -----
  2023-12  18
  2024-01  32
groupBy: month:dateUpdated
```

```json
{
  "success": true,
  "total": 5000,
  "buckets": [
    { "key": "Technology", "count": 120 },
    { "key": "(empty)", "count": 40 }
  ],
  "truncated": true,
  "groupBy": "category"
}
```

`buckets` is capped at 200 entries; when a grouping produces more distinct values than that, only the top 200 are returned and `truncated` is `true`.

---

### get_entry

Retrieve a single entry by id or slug, in the same payload format `create_entry` and `update_entry` accept as `fields`. Use this to read an entry before editing it, or to inspect exactly what a write should send.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `id` | int | null | Entry id |
| `slug` | string | null | Entry slug |
| `section` | string | null | Section handle (recommended when using slug to avoid ambiguity) |
| `site` | string | null | Site handle to query and read fields from (defaults to the primary site) |

At least one of `id` or `slug` is required. If both are given, the entry must match both (they're combined, not `id` taking precedence).

**Examples:**

```
# Get by id
get_entry id=123

# Get by slug (specify section to avoid conflicts)
get_entry slug="about" section="pages"

# Get a specific site's version of an entry
get_entry id=123 site="german"
```

**Response:**

Returns a single entry object with the same structure as `list_entries` (see above), or an error if the entry isn't found:

```json
{
  "found": false,
  "error": "Entry not found"
}
```

An `id` lookup also matches drafts and revisions, so an agent can read back the draft a previous write just created, or inspect a past revision's content using the `revisionElementId` from `list_revisions`.

---

### create_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Create an entry. `fields` is JSON in the payload format (natural keys: `{section, slug}` for entries, `{volume, filename}` for assets, Matrix blocks by entry-type handle). Saves as a draft unless `mode` or the `entryWriteMode` setting says `live`. Call `describe_entry_schema` first to learn the shape.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | Yes | Section handle where the entry will be created |
| `type` | string | Yes | Entry type handle |
| `title` | string | Yes | Entry title |
| `slug` | string | No | URL slug (auto-generated from title if omitted) |
| `site` | string | No | Site to create the entry on (defaults to the primary site) |
| `fields` | string | No | JSON string in the payload format, containing custom field values |
| `mode` | string | No | `"draft"` or `"live"`, overriding the `entryWriteMode` setting for this call |
| `parent` | string | No | Parent entry: its numeric id, or its slug within the same section |

**Examples:**

```
# Create a simple entry
create_entry section="news" type="default" title="New Article"

# Create with custom fields in the payload format
create_entry section="pages" type="page" title="About Us" fields='{"category": [{"section": "topics", "slug": "company"}]}'

# Create live instead of as a draft
create_entry section="news" type="default" title="Breaking News" mode="live"

# Create under a parent entry, referenced by slug
create_entry section="pages" type="page" title="Team" parent="about"
```

**Response:**

```json
{
  "success": true,
  "action": "created",
  "elementId": 789,
  "draftId": 12,
  "draftElementId": 790,
  "state": "draft",
  "cpEditUrl": "https://example.com/admin/entries/news/790-new-article",
  "previewUrl": "https://example.com/news/new-article?token=…",
  "warnings": [],
  "errors": []
}
```

`elementId` is the canonical entry's id. When the write lands as a draft, `draftElementId` is the id to pass to `update_entry` or `publish_entry` next, `cpEditUrl` is a ready-to-open control panel link for human review, and `previewUrl` renders the draft as saved without a login (null for sections without URLs, or with `previewUrls` off). On failure, `success` is `false`, `elementId` is `null`, and `errors` carries per-field validation messages keyed by attribute or field path.

---

### update_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Update an entry by id. In draft mode (the default) a live entry gets a draft on top rather than being changed directly; `publish_entry` applies it. `fields` is payload-format JSON; only the values you supply change.

> **Note:** This is a dangerous tool that can be disabled via configuration.

> **Note:** `id` doesn't have to be a top-level entry. Any Matrix-family block is addressable by its own id (the block key `get_entry` returned) and editing it directly leaves the owner's field value, and every sibling block, untouched. See [Content Writing: Editing one block directly](../content-writing.md#editing-one-block-directly).

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | id of the entry to update |
| `site` | string | No | Site to update (defaults to the primary site) |
| `title` | string | No | New title |
| `slug` | string | No | New URL slug |
| `status` | string | No | New status; `"live"` or `"enabled"` enables the entry, anything else disables it |
| `fields` | string | No | JSON string in the payload format, containing field values to update |
| `mode` | string | No | `"draft"` or `"live"`, overriding the `entryWriteMode` setting for this call |
| `parent` | string | No | New parent entry: its numeric id, or its slug within the entry's own section |
| `expectedDateUpdated` | string | No | The `dateUpdated` string `get_entry` returned; the write fails when the entry changed since, instead of overwriting the newer content |

**Examples:**

```
# Update title
update_entry id=123 title="Updated Title"

# Change status
update_entry id=123 status="disabled"

# Update custom fields in the payload format
update_entry id=123 fields='{"summary": "Updated summary text"}'

# Update live instead of drafting on top
update_entry id=123 title="New Title" mode="live"

# Reject the write if anything else touched the entry since the read
update_entry id=123 fields='{"summary": "..."}' expectedDateUpdated="2024-01-15 14:22:00"
```

When `expectedDateUpdated` is given and the canonical entry's `dateUpdated` no longer matches it, the call fails naming both timestamps; re-read with `get_entry` and rebuild the payload from the fresh values. Timestamps compare as instants, so timezone formatting differences never produce false conflicts. Omitting the parameter keeps the previous unchecked behavior. See [Content Writing: Conflict detection](../content-writing.md#conflict-detection-expecteddateupdated).

**Response:**

Same shape as `create_entry`, with `action` set to `"updated"`:

```json
{
  "success": true,
  "action": "updated",
  "elementId": 123,
  "draftId": 8,
  "draftElementId": 456,
  "state": "draft",
  "cpEditUrl": "https://example.com/admin/entries/news/456-updated-title",
  "previewUrl": "https://example.com/news/updated-title?token=…",
  "warnings": [],
  "errors": []
}
```

---

### create_nested_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Add a single block to a Matrix field on an owner entry, without resending the owner's other blocks. This is the direct "create a card" path: rewriting the owner's whole field value through `update_entry` replaces the field's entire contents and deletes any block left out of the payload, while this tool touches nothing but the new block. It also covers the first-ever block of a type that doesn't appear anywhere in the field yet, which no duplicate-based workaround can reach.

In draft mode (the default) the block lands on a draft **of the owner**, exactly like adding a block in the control panel: siblings and the live page stay untouched until `publish_entry` applies the owner draft. Passing an `owner` that already is a draft's element id stacks the block onto that same draft instead of creating another one.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `owner` | int | Yes | id of the entry that owns the Matrix field; pass a `draftElementId` from an earlier response to stack more blocks onto the same owner draft |
| `field` | string | Yes | Matrix field handle on the owner |
| `type` | string | Yes | Entry type handle of the block to create; `describe_entry_schema` lists the valid handles under the field's `blockTypes` |
| `title` | string | No | Block title, for block types that show one |
| `fields` | string | No | JSON string in the payload format with the block's custom field values |
| `site` | string | No | Site handle (defaults to the primary site) |
| `mode` | string | No | `"draft"` or `"live"`, overriding the `entryWriteMode` setting for this call |
| `position` | int | No | 1-based position among the field's blocks; omitted appends at the end, past-the-end clamps to the last slot |

**Examples:**

```
# Add a block at the end of the field, as a draft of the owner
create_nested_entry owner=123 field="contentBuilder" type="text" fields='{"contentExtensive": "<p>Hello</p>"}'

# Add a quote block as the second block
create_nested_entry owner=123 field="contentBuilder" type="quote" position=2 fields='{"quote": "Less, but better."}'

# Stack a second block onto the same owner draft
create_nested_entry owner=890 field="contentBuilder" type="text"
```

**Response:**

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
  "cpEditUrl": "https://example.com/admin/entries/pages/890-about",
  "previewUrl": "https://example.com/about?token=…",
  "warnings": [],
  "errors": []
}
```

`blockId` is the new block's own entry id. `ownerId` is the canonical owner. `draftElementId` is the **owner draft's** element id: what `publish_entry` accepts, what `get_entry` shows the block in context on, and what follow-up `create_nested_entry`/`update_entry` calls take to keep working on the same draft; both draft keys are `null` for live-mode calls, whose `state` is `"live"`. `position` is the 1-based slot the block actually took (a clamped position reports the real one). After publishing, re-read the owner: Craft duplicates a draft-owned block onto the canonical entry on apply, so the block's final id comes from the published owner, not from this response.

Failure responses carry `success: false` and per-field validation messages under `errors`, same as `create_entry`. Unknown `field` handles fail listing the owner's available Matrix handles; unknown `type` handles fail listing the field's allowed types; revision owners are rejected by name.

---

### move_nested_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Move a Matrix block to a new 1-based position within its field, by the block's own entry id. In draft mode (the default) the reorder lands on a draft of the owner entry for review, and `publish_entry` applies it; live mode reorders the canonical entry directly.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | The block's own entry id (the block key `get_entry` returned) |
| `position` | int | Yes | Target 1-based position; past-the-end clamps to the last slot |
| `site` | string | No | Site handle (defaults to the primary site) |
| `mode` | string | No | `"draft"` or `"live"`, overriding the `entryWriteMode` setting for this call |

**Example:**

```
# Make block 512 the first block of its field, staged on an owner draft
move_nested_entry id=512 position=1
```

**Response:**

Same shape as `create_nested_entry`, with `action` set to `"moved"` and `position` reporting the slot actually taken.

The tool rejects, with named errors instead of a silent success: ids that aren't nested elements, draft or revision copies of a block (the message names the canonical block id to use), blocks living in non-Matrix fields (a CKEditor-nested entry's order is markup-driven, so it has no position to move), and owners that are revisions.

---

### describe_entry_schema

Describe the fields a section/entry type accepts before writing to it: handles, kinds, required flags, a per-field `input` shape describing the exact payload each field takes (natural keys for relations, block types for Matrix, link/option/table/container shapes for structured and third-party fields), native fields, and writable meta attributes. Pass `example` to include a real entry's payload as a golden fixture.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | (required) | Section handle |
| `type` | string | the section's first entry type | Entry type handle |
| `depth` | int | 1 | How many levels deep to expand nested structures (Matrix block sub-fields, container fields) |
| `example` | string | null | Entry id or slug within `section`; when given, its payload is included as `example` |

**Examples:**

```
# Describe the default entry type in a section
describe_entry_schema section="pages"

# Describe a specific entry type, expanding two levels of nested fields
describe_entry_schema section="pages" type="page" depth=2

# Include a real entry as a worked example
describe_entry_schema section="pages" type="page" example="about"
```

**Response:**

```json
{
  "section": "pages",
  "type": "page",
  "flags": {
    "hasTitleField": true,
    "showSlugField": true,
    "showStatusField": true
  },
  "meta": ["slug", "postDate", "expiryDate", "enabled", "authorIds"],
  "natives": [
    { "attribute": "title", "name": "Title", "required": true, "mandatory": true }
  ],
  "fields": [
    {
      "handle": "category",
      "name": "Category",
      "type": "craft\\fields\\Entries",
      "kind": "relation",
      "instructions": "",
      "required": false,
      "input": {
        "kind": "relation",
        "multiple": true,
        "elementType": "craft\\elements\\Entry",
        "item": ["section", "slug"]
      },
      "target": {
        "elementType": "craft\\elements\\Entry",
        "sources": "*"
      }
    },
    {
      "handle": "contentBuilder",
      "name": "Content Builder",
      "type": "craft\\fields\\Matrix",
      "kind": "matrix",
      "instructions": "",
      "required": false,
      "input": {
        "kind": "matrix",
        "payload": "{blockKey: {type, enabled, title?, fields}}",
        "blockTypes": {
          "contentBlock": { "hasTitleField": false }
        }
      },
      "blockTypes": [
        {
          "handle": "contentBlock",
          "name": "Content Block",
          "hasTitleField": false,
          "fields": [
            { "handle": "contentExtensive", "name": "Content", "kind": "scalar", "required": false, "input": { "kind": "scalar", "valueType": "..." } }
          ]
        }
      ]
    }
  ]
}
```

`meta` lists the entry's writable native attributes: everything Craft's own validation allows on save, minus internal bookkeeping attributes (`id`, `uid`, `siteId`, `siteSettingsId`, `fieldLayoutId`, `contentId`, `canonicalId`, `dateCreated`, `dateUpdated`, `dateDeleted`, `dateLastMerged`, `draftId`, `revisionId`, structure attributes, and similar) and custom field handles, which are covered separately under `fields`. The exact list is inferred per entry type and Craft version; call this tool for the section you're writing to rather than assuming.

Relation fields carry both a top-level `target` (the related element type and its configured sources) and an `input.item` key shape. `target.sources` is either the string `"*"`, meaning the field accepts every source, or a list where each entry names the natural-key parts one configured source pins down, in the same vocabulary a write takes:

```json
"target": {
  "elementType": "craft\\elements\\Entry",
  "sources": [
    { "section": "blog" },
    { "set": "singles" },
    { "unresolved": "section:70b2614b-639a-4de2-8c11-3851d36d6f8c" }
  ]
}
```

So a relation to the first source is written as `{"section": "blog", "slug": "..."}`. An asset folder pins down the path inside its volume too (`{"volume": "images", "path": "logos/"}`), dropped at the volume root. `set` is one of Craft's own named source sets (`singles`, `admins`, `credentialed`, `inactive`), which state a rule rather than name a container. `unresolved` is a source key that could not be turned into a handle, because the section or group it names has been deleted or belongs to an element type this server has no lookup for; the raw key is reported so the stale field setting can be found, and it is never a target a write can use. Matrix fields carry a top-level `blockTypes` list, the single depth-expanded representation of each type's sub-fields (same shape as this response's own `fields`, including per-field `instructions`), plus a flat `input.blockTypes` map (`{handle: {hasTitleField}}`) that only enumerates the valid block-type handles for the write payload. See [Content Writing: Discover the Shape First](../content-writing.md#discover-the-shape-first-describe_entry_schema) for the full table of `kind` values and what each `input` shape means.

---

### list_drafts

List pending (non-provisional) entry drafts awaiting review, newest first. This is the review queue for the draft-first workflow: everything create_entry, update_entry, and duplicate_entry produce lands here until it is published or discarded.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | No | Filter by section handle |
| `site` | string | No | Filter by site handle |
| `creator` | string | No | Filter by the draft creator's username or email |
| `limit` | int | No | Maximum drafts to return (default: 20) |
| `offset` | int | No | Offset for pagination (default: 0) |

**Response rows:** `draftElementId` (what publish_entry and get_entry accept), `canonicalId` (the live entry it belongs to; equals the draft for brand-new entries, flagged by `isNewEntry`), `title`, `section`, `type`, `site`, `creator`, `notes`, `dateUpdated`, and a `cpEditUrl` deep link for reviewing the draft in the control panel.

**Example:**

```
list_drafts section="pages" creator="anna@site.nl"
```

The `review_pending_drafts` prompt walks through this queue conversationally: inspect, publish, or reject each draft.

---

### list_revisions

List an entry's saved revisions, newest first: who saved each one, when, and with what notes. Answers "when did this change and by whom". Read a revision's full content with `get_entry` using its `revisionElementId`; the canonical entry id always holds the current content.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | Canonical entry id whose revisions to list |
| `site` | string | No | Site handle (defaults to the primary site) |
| `limit` | int | No | Maximum revisions to return (default: 20) |
| `offset` | int | No | Offset for pagination (default: 0) |

**Example:**

```
list_revisions id=123
```

**Response:**

```json
{
  "count": 2,
  "total": 2,
  "limit": 20,
  "offset": 0,
  "hasMore": false,
  "revisions": [
    {
      "revisionElementId": 512,
      "canonicalId": 123,
      "revisionNum": 4,
      "title": "About Us",
      "creator": "anna",
      "notes": "Updated hero copy",
      "dateCreated": "2024-01-15 14:22:00"
    },
    {
      "revisionElementId": 498,
      "canonicalId": 123,
      "revisionNum": 3,
      "title": "About Us",
      "creator": "anna",
      "notes": null,
      "dateCreated": "2024-01-10 09:15:00"
    }
  ]
}
```

`revisionElementId` is what `get_entry` accepts to read that revision's full content. `canonicalId` is the live entry the revision belongs to. `revisionNum` is Craft's sequential revision number for the entry. `creator` and `notes` come from whoever saved the revision and any note left at the time; either can be `null`.

---

### publish_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Publish an entry: applies a draft (by draft element id, or a canonical id with exactly one pending draft) to its canonical entry, or enables a disabled live entry.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | A draft's element id (`draftElementId` from a write response), or a canonical entry's id |
| `site` | string | No | Site handle (defaults to the primary site) |

**Examples:**

```
# Apply a specific draft
publish_entry id=790

# Publish a canonical entry's single pending draft
publish_entry id=123
```

**Response:**

```json
{
  "success": true,
  "entry": { "id": 123, "state": "live", "cpEditUrl": "https://example.com/admin/entries/pages/123-about" }
}
```

`entry` is the full payload, in the same shape as `get_entry`'s response, reflecting the entry after publishing. If the canonical entry has more than one pending draft, the call fails with an error listing each draft's element id so you can pick one. If it has no pending draft and is already enabled, it fails with "nothing to publish".

---

### delete_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Soft-delete an entry: moves it to the trash, restorable from the control panel.

> **Note:** This is a dangerous tool that can be disabled via configuration.

> **Note:** `id` can be a single Matrix-family block's own id, removing just that block without touching the owner entry's field value or any sibling block. See [Content Writing: Editing one block directly](../content-writing.md#editing-one-block-directly).

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | id of the entry to delete |
| `site` | string | No | Site handle (defaults to the primary site) |

**Example:**

```
delete_entry id=123
```

**Response:**

```json
{
  "success": true,
  "deleted": 123,
  "restorable": true
}
```

---

### duplicate_entry

> Requires the **Pro** edition. See [Editions](../editions.md).

Duplicate an entry as an unpublished draft. Optional title/slug overrides and a payload-format `fields` JSON let you say "like this entry, but change these fields".

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | id of the entry to duplicate |
| `site` | string | No | Site handle (defaults to the primary site) |
| `title` | string | No | Title for the duplicate (defaults to the source entry's title) |
| `slug` | string | No | Slug for the duplicate |
| `fields` | string | No | JSON string in the payload format; only the values you supply differ from the source |

**Examples:**

```
# Duplicate as-is
duplicate_entry id=123

# Duplicate with overrides
duplicate_entry id=123 title="About Us (Copy)" fields='{"summary": "A new summary"}'
```

**Response:**

```json
{
  "success": true,
  "entry": { "id": 891, "state": "draft", "cpEditUrl": "https://example.com/admin/entries/pages/891-about-us-copy" },
  "previewUrl": "https://example.com/about-us-copy?token=…"
}
```

`entry` is the full payload, in the same shape as `get_entry`'s response. The duplicate is always an unpublished draft, regardless of the `entryWriteMode` setting; publish it with `publish_entry` once reviewed.

---

### copy_entry_to_site

> Requires the **Pro** edition. See [Editions](../editions.md).

Copy an entry's field values from one site to another as a draft on the target site. Copies values; it does not machine-translate.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | id of the entry to copy |
| `fromSite` | string | Yes | Site handle to read the source values from |
| `toSite` | string | Yes | Site handle to write the draft to |

**Example:**

```
copy_entry_to_site id=123 fromSite="default" toSite="german"
```

**Response:**

Same shape as `create_entry`/`update_entry`:

```json
{
  "success": true,
  "action": "updated",
  "elementId": 123,
  "draftId": 9,
  "draftElementId": 892,
  "state": "draft",
  "cpEditUrl": "https://example.com/admin/entries/pages/892-about",
  "previewUrl": "https://example.com/about?token=…",
  "warnings": [],
  "errors": []
}
```

The entry must already exist on `toSite` (its section must be enabled for that site); the call fails with a clear error otherwise. The copy always lands as a draft; review and `publish_entry` it once translated.

---

## Assets

Assets represent files managed by Craft: images, documents, videos, and other uploads. These tools let AI assistants browse and inspect your media library, and, on the Pro edition, upload, edit, move and trash files with `upload_asset`, `update_asset` and `delete_asset`.

### list_assets

Query assets with filtering by volume, file type, or filename.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `volume` | string | null | Filter by volume handle |
| `kind` | string | null | Filter by file kind: "image", "video", "pdf", "document", etc. |
| `filename` | string | null | Filter by filename (partial match) |
| `folderId` | int | null | Filter by specific folder ID |
| `limit` | int | 50 | Maximum assets to return |
| `offset` | int | 0 | Number of assets to skip |

**Examples:**

```
# Get images from a specific volume
list_assets volume="images" kind="image" limit=50

# Search for files by name
list_assets filename="logo"

# Get all PDFs
list_assets kind="pdf"
```

**Response:**

```json
{
  "count": 10,
  "total": 234,
  "limit": 50,
  "offset": 0,
  "hasMore": true,
  "assets": [
    {
      "id": 456,
      "title": "Hero Image",
      "filename": "hero-image.jpg",
      "kind": "image",
      "size": 245678,
      "width": 1920,
      "height": 1080,
      "url": "https://example.com/uploads/hero-image.jpg",
      "volumeId": 3,
      "folderId": 12,
      "dateCreated": "2024-01-15 10:30:00",
      "dateModified": "2024-01-15 14:22:00"
    }
  ]
}
```

---

### get_asset

Get detailed information about a single asset, including dimensions, file size, and custom field values.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | Asset ID |

**Example:**

```
get_asset id=456
```

**Response:**

```json
{
  "found": true,
  "asset": {
    "id": 456,
    "title": "Hero Image",
    "filename": "hero-image.jpg",
    "kind": "image",
    "size": 245678,
    "mimeType": "image/jpeg",
    "width": 1920,
    "height": 1080,
    "url": "https://example.com/uploads/hero-image.jpg",
    "volumeHandle": "images",
    "folderId": 12,
    "folderPath": "uploads/banners",
    "dateCreated": "2024-01-10T09:15:00+00:00",
    "fields": {
      "altText": "A beautiful landscape",
      "credit": "Photo by John Doe"
    }
  }
}
```

---

### list_asset_folders

List the folder structure within a specific asset volume, or across every volume's root folders when `volume` is omitted.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `volume` | string | null | Volume handle; when omitted, lists root folders across every volume (in which case `parentId` is ignored) |
| `parentId` | int | null | List this folder's children instead of the volume's root folder; only used when `volume` is also given |

**Example:**

```
list_asset_folders volume="images"
```

**Response:**

```json
{
  "count": 5,
  "folders": [
    {
      "id": 1,
      "name": "Banners",
      "path": "banners",
      "volumeId": 3,
      "parentId": null
    },
    {
      "id": 2,
      "name": "Team Photos",
      "path": "team-photos",
      "volumeId": 3,
      "parentId": null
    }
  ]
}
```

---

### list_volumes

List all asset volumes (storage locations) configured in Craft, including filesystem type and public URL.

**Parameters:** None

**Example:**

```
list_volumes
```

**Response:**

```json
{
  "count": 2,
  "volumes": [
    {
      "id": 1,
      "handle": "images",
      "name": "Images",
      "type": "craft\\fs\\Local",
      "hasUrls": true,
      "rootUrl": "https://example.com/uploads"
    }
  ]
}
```

---

### upload_asset

> Requires the **Pro** edition. See [Editions](../editions.md).

Upload a file into a volume. Assets have no drafts, so the asset is live as soon as it is saved. The response carries the asset's natural key, which is what an assets field takes in the payload format.

> **Note:** This is a dangerous tool that can be disabled via configuration.

Pass exactly one of `url` or `data`:

- **`url`**: the server fetches the file. Only `https` on port 443, with no credentials in the URL. Every address the host resolves to must be public: loopback, private, link-local (such as the cloud metadata address `169.254.169.254`), carrier-grade NAT and reserved ranges are refused. The connection is pinned to the address that was checked, so a DNS answer that changes in between cannot redirect it. Redirects are not followed; pass the final URL. The download stops once it passes `assetUploadMaxBytes`, and times out after 30 seconds.
- **`data`**: the file as base64, or as a `data:` URI. For small files such as an SVG or an icon.

Both are capped by the [`assetUploadMaxBytes`](../configuration.md#asset-uploads) setting (20 MB by default). Craft's `allowedFileExtensions` applies as it does in the control panel.

On a `content` token the write follows the token user's Craft permissions for the volume: **Upload files** (`saveAssets`), and **Create subfolders** (`createFolders`) when `folder` does not exist yet.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `volume` | string | Yes | Volume handle |
| `filename` | string | Yes | Filename including its extension |
| `url` | string | One of `url`/`data` | `https` URL the server fetches |
| `data` | string | One of `url`/`data` | The file as base64 or a `data:` URI |
| `folder` | string | No | Folder path in the volume, such as `"blog/2026"`; omit for the root. Missing folders are created |
| `title` | string | No | Asset title (Craft derives one from the filename when omitted) |
| `alt` | string | No | Alternative text |
| `fields` | string | No | JSON string in the payload format, for the volume's custom fields |
| `onConflict` | string | No | `"rename"` (default) gives the file a unique name when one with this name exists; `"fail"` refuses the upload |

**Example:**

```
# Fetch a generated image into the blog folder
upload_asset volume="images" folder="blog/2026" filename="hero.jpg" url="https://cdn.example.com/generated/hero.jpg" alt="A lighthouse at dusk"

# A small SVG passed inline
upload_asset volume="icons" filename="arrow.svg" data="PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4="
```

**Response:**

```json
{
  "success": true,
  "action": "created",
  "asset": {
    "id": 456,
    "title": "Hero",
    "filename": "hero.jpg",
    "kind": "image",
    "size": 245678,
    "width": 1920,
    "height": 1080,
    "url": "https://example.com/uploads/blog/2026/hero.jpg",
    "volumeId": 1,
    "folderId": 12,
    "folderPath": "blog/2026/",
    "alt": "A lighthouse at dusk",
    "fields": {}
  },
  "key": {"volume": "images", "path": "blog/2026/", "filename": "hero.jpg"},
  "cpEditUrl": "https://example.com/admin/assets/edit/456",
  "warnings": []
}
```

Use `key` in an entry's assets field: `fields='{"heroImage": [{"volume": "images", "path": "blog/2026/", "filename": "hero.jpg"}]}'`. A failed save returns `"success": false` with per-field `errors`, such as a disallowed extension or, with `onConflict="fail"`, a filename that is taken.

---

### update_asset

> Requires the **Pro** edition. See [Editions](../editions.md).

Change an asset's title, alt text or custom fields, rename its file, or move it to another folder in the same volume. Only the values you pass change. The file's contents cannot be replaced.

> **Note:** This is a dangerous tool that can be disabled via configuration.

Address the asset by `id`, or by its natural key: `volume`, `folder` and `filename`. On a `content` token, editing needs **Save assets** for the volume (or the peer variant for someone else's upload). Moving also needs **Delete assets**, as it does in the control panel, and **Create subfolders** when the target folder does not exist.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | No | Asset id |
| `volume` | string | No | Volume handle, when addressing by natural key |
| `folder` | string | No | Folder path the asset is in, when addressing by natural key (omit for the root) |
| `filename` | string | No | Current filename, when addressing by natural key |
| `title` | string | No | New title |
| `alt` | string | No | New alternative text |
| `fields` | string | No | JSON string in the payload format; only the handles present are written |
| `newFilename` | string | No | Rename the file (keep the extension) |
| `newFolder` | string | No | Move the file to this folder path in the same volume (`""` for the root) |

**Example:**

```
# Fix the alt text
update_asset id=456 alt="A lighthouse at dusk, seen from the dunes"

# Rename and move, addressed by natural key
update_asset volume="images" folder="blog/2026" filename="hero.jpg" newFilename="lighthouse.jpg" newFolder="archive"
```

**Response:** the same shape as `upload_asset`, with `action` set to `"updated"` and the asset's new filename, folder and key.

---

### delete_asset

> Requires the **Pro** edition. See [Editions](../editions.md).

Soft-delete an asset: moves it to the trash, restorable from the control panel. Address it by `id`, or by `volume`, `folder` and `filename`.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Example:**

```
delete_asset id=456
```

**Response:**

```json
{
  "success": true,
  "deleted": 456,
  "restorable": true
}
```

---

## Categories

Categories in Craft are hierarchical taxonomies for organizing content. Each category belongs to a category group.

### list_categories

List categories. Filter by group handle; when omitted, returns categories across all groups.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `group` | string | null | Category group handle |
| `limit` | int | 100 | Maximum categories to return |
| `offset` | int | 0 | Number of categories to skip (for pagination) |

**Example:**

```
list_categories group="topics"
```

**Response:**

```json
{
  "count": 8,
  "total": 8,
  "limit": 100,
  "offset": 0,
  "hasMore": false,
  "categories": [
    {
      "id": 10,
      "title": "Technology",
      "slug": "technology",
      "level": 1,
      "groupId": 3,
      "groupHandle": "topics",
      "url": "https://example.com/topics/technology"
    },
    {
      "id": 11,
      "title": "Software",
      "slug": "software",
      "level": 2,
      "groupId": 3,
      "groupHandle": "topics",
      "url": "https://example.com/topics/technology/software"
    }
  ]
}
```

`level` indicates the category's depth within its group's hierarchy. The response doesn't include a parent id or custom field values.

---

## Users

These tools let AI assistants query Craft users, which is useful for understanding content authorship or building user-related features. On the Pro edition, `create_user`, `update_user` and `set_user_status` add and manage accounts, on `full` tokens and stdio only.

### list_users

List Craft users with optional filtering by group, status, or email.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `group` | string | null | Filter by user group handle |
| `status` | string | null | Filter by status: "active", "pending", "suspended" |
| `email` | string | null | Filter by email (partial match) |
| `limit` | int | 50 | Maximum users to return |
| `offset` | int | 0 | Number of users to skip (for pagination) |

**Examples:**

```
# Get all editors
list_users group="editors"

# Search by email domain
list_users email="@example.com"

# Get active admin users
list_users group="admins" status="active"
```

**Response:**

```json
{
  "count": 5,
  "total": 5,
  "limit": 50,
  "offset": 0,
  "hasMore": false,
  "users": [
    {
      "id": 1,
      "username": "admin",
      "email": "admin@example.com",
      "fullName": "Site Administrator",
      "admin": true,
      "status": "active",
      "groups": ["admins", "editors"],
      "lastLoginDate": "2024-01-15 10:30:00",
      "dateCreated": "2023-06-01 00:00:00"
    }
  ]
}
```

---

### create_user

> Requires the **Pro** edition. See [Editions](../editions.md).

Create a user account. It is created **pending**: the person cannot sign in until they activate it, or until `set_user_status` makes it active.

> **Note:** This is a dangerous tool that can be disabled via configuration. User writes are available on `full` tokens and over stdio only; a `content` or `readonly` token never sees them. See [HTTP Transport](../http-transport.md).

User writes never touch an admin account or the account the connection acts as, never set the admin flag, and never set a password or change an existing account's email. Those changes are made in the control panel.

Craft's edition limits apply: Craft Solo allows one user account and Team five, so `create_user` is refused once the install has that many, naming the limit. User groups are a Craft Pro feature, so `groups` is refused below Pro.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `email` | string | Yes | Email address for the new account |
| `username` | string | No | Username (defaults to the email address) |
| `firstName` | string | No | First name |
| `lastName` | string | No | Last name |
| `groups` | array of string | No | User group handles to add the account to |
| `sendActivationEmail` | bool | No | Send Craft's activation email. Off by default: an email to a real person is a side effect to ask for |

**Example:**

```
create_user email="sam@example.com" firstName="Sam" lastName="Jansen" groups=["editors"]
```

**Response:**

```json
{
  "success": true,
  "action": "created",
  "user": {
    "id": 42,
    "username": "sam@example.com",
    "email": "sam@example.com",
    "fullName": "Sam Jansen",
    "admin": false,
    "status": "pending",
    "groups": ["editors"],
    "lastLoginDate": null,
    "dateCreated": "2026-10-14 21:30:00"
  },
  "activationEmailSent": false
}
```

---

### update_user

> Requires the **Pro** edition. See [Editions](../editions.md).

Change a user's first name, last name or groups. `groups` is the complete set and replaces the current one; pass `[]` to remove the account from every group.

> **Note:** This is a dangerous tool, on `full` tokens and stdio only. The rules under `create_user` apply.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `user` | string | Yes | Username or email address |
| `firstName` | string | No | New first name |
| `lastName` | string | No | New last name |
| `groups` | array of string | No | The full set of group handles |

**Example:**

```
update_user user="sam@example.com" groups=["editors", "reviewers"]
```

**Response:** `{"success": true, "action": "updated", "user": {...}}`, with the user in the `create_user` shape.

---

### set_user_status

> Requires the **Pro** edition. See [Editions](../editions.md).

Set a user's account status through Craft's own user service.

> **Note:** This is a dangerous tool, on `full` tokens and stdio only. The rules under `create_user` apply.

| `status` | Effect |
|---|---|
| `active` | Activates a pending or inactive account, or lifts a suspension |
| `suspended` | The person can no longer sign in; the account and its content stay |
| `inactive` | Deactivates the account |

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `user` | string | Yes | Username or email address |
| `status` | string | Yes | `active`, `suspended` or `inactive` |

**Example:**

```
set_user_status user="sam@example.com" status="active"
```

**Response:** `{"success": true, "action": "status", "user": {...}}`, with the user in the `create_user` shape.

---

## Globals

Global sets in Craft store site-wide content that doesn't fit into entries: things like site settings, contact information, or social media links.

### list_globals

List all global sets with their current field values.

**Parameters:** None

**Example:**

```
list_globals
```

**Response:**

```json
{
  "count": 2,
  "globals": [
    {
      "handle": "siteSettings",
      "name": "Site Settings",
      "fields": {
        "siteName": "My Website",
        "tagline": "Welcome to our site",
        "copyrightYear": 2024
      }
    },
    {
      "handle": "contactInfo",
      "name": "Contact Information",
      "fields": {
        "email": "hello@example.com",
        "phone": "+1 234 567 8900",
        "address": "123 Main Street"
      }
    }
  ]
}
```

This tool is useful for understanding what site-wide configuration values are available and their current settings.
