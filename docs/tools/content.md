# Content Tools

Content tools let AI assistants query and manage the core content types in your Craft installation: entries, assets, categories, users, and global sets.

## Entries

Entries are the primary content type in Craft CMS. These tools provide full CRUD operations for working with entries across all sections.

### list_entries

Query entries with flexible filtering options. This is the primary tool for exploring content in your Craft installation.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | null | Filter by section handle (e.g., "news", "blog") |
| `type` | string | null | Filter by entry type handle |
| `status` | string | null | Filter by status: "live", "pending", "disabled", or "any" |
| `limit` | int | 20 | Maximum number of entries to return |
| `offset` | int | 0 | Number of entries to skip (for pagination) |

**Examples:**

```
# Get the 10 most recent news entries
list_entries section="news" limit=10

# Get all live blog posts of a specific type
list_entries section="blog" type="article" status="live"

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
      "title": "My Entry",
      "slug": "my-entry",
      "status": "live",
      "sectionHandle": "news",
      "typeHandle": "default",
      "dateCreated": "2024-01-15T10:30:00+00:00",
      "dateUpdated": "2024-01-15T14:22:00+00:00",
      "url": "https://example.com/news/my-entry",
      "fields": {
        "summary": "A brief description",
        "featuredImage": [{ "id": 456, "url": "..." }]
      }
    }
  ]
}
```

The `fields` object contains all custom field values for each entry, with the structure depending on your field layout.

---

### get_entry

Retrieve a single entry by ID or slug, including all custom field values. Use this when you need complete details about a specific entry.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `id` | int | null | Entry ID (takes precedence if both provided) |
| `slug` | string | null | Entry slug |
| `section` | string | null | Section handle (recommended when using slug to avoid ambiguity) |

**Examples:**

```
# Get by ID
get_entry id=123

# Get by slug (specify section to avoid conflicts)
get_entry slug="my-entry" section="news"
```

**Response:**

Returns a single entry object with the same structure as `list_entries`, or an error if the entry isn't found:

```json
{
  "found": false,
  "error": "Entry not found"
}
```

---

### create_entry

Create a new entry in any section. This tool allows AI assistants to generate content directly in Craft.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | Yes | Section handle where the entry will be created |
| `type` | string | Yes | Entry type handle |
| `title` | string | Yes | Entry title |
| `slug` | string | No | URL slug (auto-generated from title if omitted) |
| `fields` | string | No | JSON string containing custom field values |

**Examples:**

```
# Create a simple entry
create_entry section="news" type="default" title="New Article"

# Create with custom fields
create_entry section="blog" type="post" title="Hello World" fields='{"summary": "A brief intro", "category": [5]}'

# Create with specific slug
create_entry section="pages" type="page" title="About Us" slug="about"
```

**Response:**

```json
{
  "success": true,
  "entry": {
    "id": 789,
    "title": "New Article",
    "slug": "new-article",
    "url": "https://example.com/news/new-article"
  }
}
```

---

### update_entry

Modify an existing entry's title, slug, status, or custom field values.

> **Note:** This is a dangerous tool that can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | ID of the entry to update |
| `title` | string | No | New title |
| `slug` | string | No | New URL slug |
| `status` | string | No | New status: "live" or "disabled" |
| `fields` | string | No | JSON string containing field values to update |

**Examples:**

```
# Update title
update_entry id=123 title="Updated Title"

# Change status
update_entry id=123 status="disabled"

# Update custom fields
update_entry id=123 fields='{"summary": "Updated summary text"}'

# Multiple changes at once
update_entry id=123 title="New Title" status="live" fields='{"featured": true}'
```

**Response:**

```json
{
  "success": true,
  "entry": {
    "id": 123,
    "title": "Updated Title",
    "slug": "updated-title",
    "status": "live"
  }
}
```

---

## Assets

Assets represent files managed by Craft—images, documents, videos, and other uploads. These tools let AI assistants browse and inspect your media library.

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

Global sets in Craft store site-wide content that doesn't fit into entries—things like site settings, contact information, or social media links.

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
