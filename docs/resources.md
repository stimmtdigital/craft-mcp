# Resources

Resources provide read-only access to Craft CMS data through standard URIs. Unlike tools that perform operations, resources are designed for AI assistants to passively read information about your installation's schema, configuration, and content.

Resources use the `craft://` URI scheme, making it easy to reference specific data. Some resources have fixed URIs, while others use URI templates with parameters like `{section}` or `{handle}`.

## Schema Resources

These resources expose your content architecture—sections, fields, and their configurations.

### craft://schema/sections

List all sections in your Craft installation with basic metadata.

**Response:**

```json
{
  "sections": [
    {
      "handle": "news",
      "name": "News",
      "type": "channel",
      "entryTypeCount": 2
    },
    {
      "handle": "homepage",
      "name": "Homepage",
      "type": "single",
      "entryTypeCount": 1
    }
  ]
}
```

---

### craft://schema/fields

List all custom fields in your installation with their types and group assignments.

**Response:**

```json
{
  "fields": [
    {
      "handle": "summary",
      "name": "Summary",
      "type": "PlainText",
      "group": "Common",
      "searchable": true
    },
    {
      "handle": "featuredImage",
      "name": "Featured Image",
      "type": "Assets",
      "group": "Media",
      "searchable": false
    }
  ]
}
```

---

### craft://schema/sections/{handle}

Get detailed schema information for a specific section, including all entry types and their field layouts.

**URI Parameters:**

| Name | Description |
|------|-------------|
| `handle` | The section handle (e.g., "news", "blog") |

**Example URI:** `craft://schema/sections/news`

**Response:**

```json
{
  "section": {
    "handle": "news",
    "name": "News",
    "type": "channel",
    "hasUrls": true,
    "enableVersioning": true
  },
  "entryTypes": [
    {
      "handle": "article",
      "name": "Article",
      "hasTitleField": true,
      "fields": [
        {
          "handle": "summary",
          "name": "Summary",
          "type": "PlainText",
          "required": true
        },
        {
          "handle": "body",
          "name": "Body",
          "type": "Matrix",
          "required": false
        }
      ]
    }
  ]
}
```

---

### craft://schema/fields/{handle}

Get detailed information about a specific field, including where it's used across your content model.

**URI Parameters:**

| Name | Description |
|------|-------------|
| `handle` | The field handle |

**Example URI:** `craft://schema/fields/featuredImage`

**Response:**

```json
{
  "field": {
    "handle": "featuredImage",
    "name": "Featured Image",
    "type": "Assets",
    "instructions": "Upload a high-resolution image for the hero section",
    "searchable": false,
    "translationMethod": "none"
  },
  "usedIn": [
    {
      "section": "news",
      "entryType": "article"
    },
    {
      "section": "blog",
      "entryType": "post"
    }
  ]
}
```

---

## Configuration Resources

These resources expose safe configuration values from your Craft installation. Sensitive data like API keys and passwords is never exposed.

### craft://config/general

Get safe general configuration values from your Craft installation.

**Response:**

```json
{
  "general": {
    "devMode": false,
    "allowAdminChanges": false,
    "allowUpdates": false,
    "cpTrigger": "admin",
    "defaultWeekStartDay": 1,
    "enableGql": true,
    "headlessMode": false,
    "isSystemLive": true,
    "maxRevisions": 10,
    "omitScriptNameInUrls": true,
    "runQueueAutomatically": true,
    "timezone": "Europe/Amsterdam",
    "useEmailAsUsername": false
  }
}
```

---

### craft://config/routes

Get custom routes configured in your Craft installation.

**Response:**

```json
{
  "routes": {
    "api/v1/<action>": "api/handle",
    "sitemap.xml": "templates/sitemap"
  }
}
```

---

### craft://config/sites

Get all configured sites with their settings.

**Response:**

```json
{
  "sites": [
    {
      "handle": "default",
      "name": "English",
      "language": "en",
      "primary": true,
      "enabled": true,
      "baseUrl": "https://example.com"
    },
    {
      "handle": "dutch",
      "name": "Nederlands",
      "language": "nl",
      "primary": false,
      "enabled": true,
      "baseUrl": "https://example.com/nl"
    }
  ]
}
```

---

### craft://config/volumes

Get all configured asset volumes.

**Response:**

```json
{
  "volumes": [
    {
      "handle": "images",
      "name": "Images",
      "fsType": "Local",
      "hasUrls": true,
      "assetCount": 234
    },
    {
      "handle": "documents",
      "name": "Documents",
      "fsType": "Local",
      "hasUrls": true,
      "assetCount": 45
    }
  ]
}
```

---

### craft://config/plugins

Get information about all installed plugins.

**Response:**

```json
{
  "plugins": [
    {
      "handle": "seo",
      "name": "SEO",
      "version": "4.0.0",
      "enabled": true,
      "developer": "Ether Creative"
    },
    {
      "handle": "mcp",
      "name": "Craft MCP",
      "version": "1.0.1",
      "enabled": true,
      "developer": "Stimmt Digital"
    }
  ]
}
```

---

## Content Resources

These resources provide access to entry content within your sections.

### craft://entries/{section}

List entries in a specific section with basic metadata. Returns up to 50 entries, ordered by most recently updated.

**URI Parameters:**

| Name | Description |
|------|-------------|
| `section` | The section handle |

**Example URI:** `craft://entries/news`

**Response:**

```json
{
  "section": "news",
  "entries": [
    {
      "id": 123,
      "title": "Latest Announcement",
      "slug": "latest-announcement",
      "status": "live",
      "dateCreated": "2024-01-15 10:30:00",
      "dateUpdated": "2024-01-15 14:22:00"
    }
  ],
  "total": 156,
  "limit": 50
}
```

---

### craft://entries/{section}/{slug}

Get a specific entry by its section and slug, including all custom field values.

**URI Parameters:**

| Name | Description |
|------|-------------|
| `section` | The section handle |
| `slug` | The entry's URL slug |

**Example URI:** `craft://entries/news/latest-announcement`

**Response:**

```json
{
  "entry": {
    "id": 123,
    "title": "Latest Announcement",
    "slug": "latest-announcement",
    "status": "live",
    "type": "article",
    "url": "https://example.com/news/latest-announcement",
    "dateCreated": "2024-01-15 10:30:00",
    "dateUpdated": "2024-01-15 14:22:00",
    "fields": {
      "summary": "A brief overview of the announcement",
      "featuredImage": [456, 789],
      "category": [10, 11]
    }
  }
}
```

---

### craft://entries/{section}/stats

Get entry statistics for a section, including counts by status and entry type.

**URI Parameters:**

| Name | Description |
|------|-------------|
| `section` | The section handle |

**Example URI:** `craft://entries/news/stats`

**Response:**

```json
{
  "section": "news",
  "stats": {
    "total": 156,
    "live": 142,
    "disabled": 8,
    "pending": 4,
    "expired": 2,
    "drafts": 12,
    "byType": {
      "article": 120,
      "pressRelease": 36
    }
  }
}
```

---

## Using Resources

Resources are read through the MCP resources interface. How you access them depends on your AI client:

**In conversation:** Your AI assistant may automatically read resources when it needs information about your installation. For example, when you ask about a section's structure, it might read `craft://schema/sections/{handle}`.

**Explicit access:** Some clients allow you to explicitly attach resources to your conversation. Check your client's documentation for resource attachment features.

Resources are read-only and always reflect the current state of your Craft installation. They're automatically updated when you make changes in the Control Panel.

## Error Handling

When a resource can't be found, it returns an error object:

```json
{
  "error": "Section 'nonexistent' not found"
}
```

This allows AI assistants to gracefully handle missing data and provide helpful feedback.
