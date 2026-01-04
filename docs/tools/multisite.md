# Multi-Site Tools

Multi-site tools help AI assistants understand and work with Craft's multi-site capabilities. Whether you're running a single site with multiple languages or a network of distinct sites, these tools surface the configuration details needed to generate site-aware code.

## Sites

Sites in Craft represent distinct front-end experiences—different domains, languages, or content variations. Each site belongs to a site group and has its own base URL, language, and enabled status.

### list_sites

List all sites configured in your Craft installation. This gives you a complete overview of your multi-site setup.

**Parameters:** None

**Example:**

```
list_sites
```

**Response:**

```json
{
  "count": 3,
  "sites": [
    {
      "id": 1,
      "uid": "a1b2c3d4-...",
      "handle": "default",
      "name": "English",
      "language": "en-US",
      "primary": true,
      "enabled": true,
      "baseUrl": "https://example.com/",
      "groupId": 1,
      "sortOrder": 1,
      "dateCreated": "2024-01-01 00:00:00",
      "dateUpdated": "2024-01-15 10:30:00"
    },
    {
      "id": 2,
      "uid": "e5f6g7h8-...",
      "handle": "german",
      "name": "German",
      "language": "de",
      "primary": false,
      "enabled": true,
      "baseUrl": "https://example.com/de/",
      "groupId": 1,
      "sortOrder": 2,
      "dateCreated": "2024-01-01 00:00:00",
      "dateUpdated": "2024-01-15 10:30:00"
    }
  ]
}
```

Key fields to note:

- **primary**: Only one site can be primary; it's the default when no site is specified
- **language**: BCP 47 language tag used for localization
- **groupId**: Sites in the same group share content propagation settings
- **enabled**: Disabled sites aren't accessible on the front-end

---

### get_site

Get detailed information about a specific site, including its site group. Use this when you need complete details about a particular site's configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | No | Site ID (takes precedence if both provided) |
| `handle` | string | No | Site handle |

At least one parameter must be provided.

**Examples:**

```
# Get by ID
get_site id=1

# Get by handle
get_site handle="german"
```

**Response:**

```json
{
  "success": true,
  "site": {
    "id": 2,
    "uid": "e5f6g7h8-...",
    "handle": "german",
    "name": "German",
    "language": "de",
    "primary": false,
    "enabled": true,
    "baseUrl": "https://example.com/de/",
    "sortOrder": 2,
    "group": {
      "id": 1,
      "name": "Main Sites"
    },
    "dateCreated": "2024-01-01 00:00:00",
    "dateUpdated": "2024-01-15 10:30:00"
  }
}
```

**Error response:**

```json
{
  "success": false,
  "error": "Site with handle 'nonexistent' not found"
}
```

---

## Site Groups

Site groups organize related sites together. Sites within the same group can share content through Craft's propagation settings—when you create an entry in one site, it can automatically propagate to other sites in the same group.

### list_site_groups

List all site groups with their associated sites. This helps you understand how your sites are organized and which sites share content.

**Parameters:** None

**Example:**

```
list_site_groups
```

**Response:**

```json
{
  "count": 2,
  "groups": [
    {
      "id": 1,
      "uid": "i9j0k1l2-...",
      "name": "Main Sites",
      "siteCount": 3,
      "siteHandles": ["default", "german", "french"]
    },
    {
      "id": 2,
      "uid": "m3n4o5p6-...",
      "name": "Microsite",
      "siteCount": 1,
      "siteHandles": ["promo"]
    }
  ]
}
```

The `siteHandles` array shows which sites belong to each group. This is useful for understanding content propagation—entries created in any site within a group can be configured to automatically appear in the other sites in that group.
