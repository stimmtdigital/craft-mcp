# Content Tools

Content tools let AI assistants query and manage the core content types in your Craft installation: entries, assets, categories, users, and global sets.

## Entries

Entries are the primary content type in Craft CMS. Reads and writes share one payload format: `get_entry` returns exactly what `create_entry` and `update_entry` accept as `fields`. Relations resolve to natural keys instead of numeric ids (`{"section": "pages", "slug": "about"}` for entries, `{"volume": "images", "filename": "hero.jpg"}` for assets), and Matrix blocks are objects keyed by block id with a `type` entry-type handle naming the block type. Writes are draft-first by default. See the [Content Writing guide](../content-writing.md) for the full format and workflow; this page covers each tool's parameters and response shape.

### list_entries

List entries. Filter by section, type, status, site handle, and full-text search. Returns entries in the payload format described above.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | null | Filter by section handle (e.g., "news", "blog") |
| `type` | string | null | Filter by entry type handle |
| `status` | string | null | Filter by status: "live", "pending", "disabled", or "any" |
| `site` | string | null | Site handle to query and read fields from (defaults to the primary site) |
| `search` | string | null | Full-text search term |
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
```

**Response:**

```json
{
  "count": 10,
  "total": 45,
  "limit": 10,
  "offset": 0,
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

An `id` lookup also matches drafts, so an agent can read back the draft a previous write just created.

---

### create_entry

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
  "warnings": [],
  "errors": []
}
```

`elementId` is the canonical entry's id. When the write lands as a draft, `draftElementId` is the id to pass to `update_entry` or `publish_entry` next, and `cpEditUrl` is a ready-to-open control panel link for human review. On failure, `success` is `false`, `elementId` is `null`, and `errors` carries per-field validation messages keyed by attribute or field path.

---

### update_entry

Update an entry by id. In draft mode (the default) a live entry gets a draft on top rather than being changed directly; `publish_entry` applies it. `fields` is payload-format JSON; only the values you supply change.

> **Note:** This is a dangerous tool that can be disabled via configuration.

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
```

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
  "warnings": [],
  "errors": []
}
```

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
          "contentBlock": {
            "hasTitleField": false,
            "fields": {
              "natives": [],
              "fields": {
                "contentExtensive": { "input": { "kind": "scalar", "valueType": "..." } }
              }
            }
          }
        }
      },
      "blockTypes": [
        { "handle": "contentBlock", "name": "Content Block", "hasTitleField": false, "fields": [] }
      ]
    }
  ]
}
```

`meta` lists the entry's writable native attributes: everything Craft's own validation allows on save, minus internal bookkeeping attributes (`id`, `uid`, `siteId`, `siteSettingsId`, `fieldLayoutId`, `contentId`, `canonicalId`, `dateCreated`, `dateUpdated`, `dateDeleted`, `dateLastMerged`, `draftId`, `revisionId`, structure attributes, and similar) and custom field handles, which are covered separately under `fields`. The exact list is inferred per entry type and Craft version; call this tool for the section you're writing to rather than assuming.

Relation fields carry both a top-level `target` (the related element type and its configured sources) and an `input.item` key shape. Matrix fields carry both a top-level `blockTypes` list (depth-expanded, in the same shape as this response's own `fields`) and an `input.blockTypes` map used purely to describe the write payload. See [Content Writing: Discover the Shape First](../content-writing.md#discover-the-shape-first-describe_entry_schema) for the full table of `kind` values and what each `input` shape means.

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

### publish_entry

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

Soft-delete an entry: moves it to the trash, restorable from the control panel.

> **Note:** This is a dangerous tool that can be disabled via configuration.

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
  "entry": { "id": 891, "state": "draft", "cpEditUrl": "https://example.com/admin/entries/pages/891-about-us-copy" }
}
```

`entry` is the full payload, in the same shape as `get_entry`'s response. The duplicate is always an unpublished draft, regardless of the `entryWriteMode` setting; publish it with `publish_entry` once reviewed.

---

### copy_entry_to_site

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
  "warnings": [],
  "errors": []
}
```

The entry must already exist on `toSite` (its section must be enabled for that site); the call fails with a clear error otherwise. The copy always lands as a draft; review and `publish_entry` it once translated.

---

## Assets

Assets represent files managed by Craft: images, documents, videos, and other uploads. These tools let AI assistants browse and inspect your media library.

### list_assets

Query assets with filtering by volume, file type, or filename.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `volume` | string | null | Filter by volume handle |
| `kind` | string | null | Filter by file kind: "image", "video", "pdf", "document", etc. |
| `filename` | string | null | Filter by filename (partial match) |
| `folderId` | int | null | Filter by specific folder ID |
| `limit` | int | 20 | Maximum assets to return |
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
      "volumeHandle": "images"
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

List the folder structure within a specific asset volume. Useful for understanding how assets are organized.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `volume` | string | Yes | Volume handle |

**Example:**

```
list_asset_folders volume="images"
```

**Response:**

```json
{
  "volume": "images",
  "count": 5,
  "folders": [
    {
      "id": 1,
      "name": "Banners",
      "path": "banners"
    },
    {
      "id": 2,
      "name": "Team Photos",
      "path": "team-photos"
    }
  ]
}
```

---

## Categories

Categories in Craft are hierarchical taxonomies for organizing content. Each category belongs to a category group.

### list_categories

List categories within a specific category group, including their hierarchy and custom field values.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `group` | string | Yes | Category group handle |
| `limit` | int | No | Maximum categories to return (default: 100) |

**Example:**

```
list_categories group="topics"
```

**Response:**

```json
{
  "count": 8,
  "group": "topics",
  "categories": [
    {
      "id": 10,
      "title": "Technology",
      "slug": "technology",
      "level": 1,
      "parentId": null,
      "fields": {
        "description": "Articles about technology"
      }
    },
    {
      "id": 11,
      "title": "Software",
      "slug": "software",
      "level": 2,
      "parentId": 10,
      "fields": {}
    }
  ]
}
```

The `level` and `parentId` fields indicate the category's position in the hierarchy.

---

## Users

These tools let AI assistants query Craft users, which is useful for understanding content authorship or building user-related features.

### list_users

List Craft users with optional filtering by group, status, or email.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `group` | string | null | Filter by user group handle |
| `status` | string | null | Filter by status: "active", "pending", "suspended" |
| `email` | string | null | Filter by email (partial match) |
| `limit` | int | 20 | Maximum users to return |
| `offset` | int | 0 | Number of users to skip |

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
  "total": 12,
  "users": [
    {
      "id": 1,
      "email": "admin@example.com",
      "username": "admin",
      "fullName": "Site Administrator",
      "status": "active",
      "groups": ["admins", "editors"],
      "dateCreated": "2023-06-01T00:00:00+00:00",
      "lastLoginDate": "2024-01-15T10:30:00+00:00"
    }
  ]
}
```

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
