# System Tools

System tools give AI assistants visibility into how your Craft installation is configured and running. From version information to log analysis to cache management, these tools help diagnose issues and understand your site's setup without digging through files manually.

## System Information

Every Craft site has its own combination of versions, settings, and configurations. The system information tool provides a quick snapshot of what's running.

### get_system_info

Get a comprehensive overview of your Craft installation, including version numbers, database configuration, and multi-site setup details.

**Parameters:** None

**Example:**

```
get_system_info
```

**Response:**

```json
{
  "craft": {
    "version": "5.0.0",
    "edition": "pro",
    "environment": "dev"
  },
  "php": {
    "version": "8.3.0"
  },
  "database": {
    "driver": "mysql",
    "version": "8.0.35"
  },
  "sites": [
    {
      "name": "My Site",
      "handle": "default",
      "language": "en-US",
      "primary": true
    }
  ]
}
```

This tool is particularly useful when debugging issues that might be version-specific, or when you need to quickly understand the multi-site structure of a Craft installation.

---

## Configuration

Craft's configuration system spans multiple files and supports environment-specific overrides. Rather than manually checking each config file, the `get_config` tool lets AI assistants read configuration values directly using dot notation.

### get_config

Read configuration values by their dot-notation key. This tool can access general config, database settings (excluding credentials), and any custom config files you've created.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `key` | string | Yes | Config key in dot notation (e.g., "general.devMode") |

**Supported key patterns:**

- `general` - Returns all general config settings
- `general.devMode` - Returns a specific general setting
- `db` - Returns safe database config values (credentials excluded)
- `db.driver` - Returns a specific database setting
- `custom.{filename}` - Returns values from custom config files in `config/`

**Examples:**

```
# Check if dev mode is enabled
get_config key="general.devMode"

# Get all general config settings
get_config key="general"

# Check the database driver
get_config key="db.driver"

# Read a custom config file
get_config key="custom.myconfig"
```

**Response:**

```json
{
  "key": "general.devMode",
  "value": true
}
```

---

## Logging

When something goes wrong, the logs tell the story. These tools let AI assistants search through Craft's log files to find errors, warnings, and other diagnostic information.

### read_logs

Search through recent log entries with optional filtering by severity level. This is useful for understanding what's happening in your application and identifying patterns in warnings or errors.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `limit` | int | 50 | Maximum number of entries to return |
| `level` | string | null | Filter by log level: "error", "warning", or "info" |

**Examples:**

```
# Get the 20 most recent log entries
read_logs limit=20

# Get only errors
read_logs level="error" limit=10

# Look for warnings
read_logs level="warning"
```

**Response:**

```json
{
  "count": 10,
  "entries": [
    {
      "file": "web.log",
      "timestamp": "2024-01-15 10:30:45",
      "level": "error",
      "category": "application",
      "message": "Error message here"
    }
  ]
}
```

---

### get_last_error

A shortcut for finding the most recent error in your logs. When users report "something broke," this tool quickly surfaces the relevant error without manually scanning log files.

**Parameters:** None

**Example:**

```
get_last_error
```

**Response:**

```json
{
  "found": true,
  "error": {
    "file": "web.log",
    "timestamp": "2024-01-15 10:30:45",
    "level": "error",
    "category": "application",
    "message": "Error message here"
  }
}
```

If no errors exist in the logs, the response will indicate `"found": false`.

---

## Caching

Craft uses several caching layers to improve performance. Sometimes you need to clear these caches—after template changes, during debugging, or when troubleshooting stale data issues.

### clear_caches

Clear one or all of Craft's cache types. This is classified as a dangerous tool because clearing caches can temporarily impact site performance while they rebuild.

> **Note:** This tool can be disabled via configuration. See the [Configuration Guide](../configuration.md).

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `type` | string | "all" | Which cache type to clear |

**Available cache types:**

| Type | What It Clears |
|------|----------------|
| `all` | All caches at once |
| `data` | Data cache (query results, element data) |
| `compiled-templates` | Compiled Twig templates |
| `temp-files` | Temporary files in storage |

**Examples:**

```
# Clear all caches
clear_caches

# Clear only the data cache
clear_caches type="data"

# Force template recompilation
clear_caches type="compiled-templates"
```

**Response:**

```json
{
  "success": true,
  "cleared": ["data", "compiled-templates", "temp-files"]
}
```

---

## Routes and Commands

Understanding how URLs map to content is essential for debugging 404 errors or planning new features. These tools expose Craft's routing system and available CLI commands.

### list_routes

Get all registered routes in your Craft installation, including those from section URL formats, category groups, and your custom `config/routes.php` file.

**Parameters:** None

**Example:**

```
list_routes
```

**Response:**

```json
{
  "count": 15,
  "routes": [
    {
      "pattern": "news/<slug>",
      "template": "news/_entry",
      "type": "section",
      "section": "news"
    },
    {
      "pattern": "api/search",
      "template": null,
      "type": "config"
    }
  ]
}
```

Each route includes a `type` field indicating its source:

| Type | Source |
|------|--------|
| `config` | Defined in `config/routes.php` |
| `section` | Auto-generated from a section's URL format |
| `category` | Auto-generated from a category group's URL format |

---

### list_console_commands

List all available CLI commands, including those added by plugins. This helps AI assistants understand what operations can be performed via the command line.

**Parameters:** None

**Example:**

```
list_console_commands
```

**Response:**

```json
{
  "count": 25,
  "commands": [
    {
      "id": "cache",
      "description": "Clear and manage caches"
    },
    {
      "id": "migrate",
      "description": "Run database migrations"
    },
    {
      "id": "project-config",
      "description": "Manage project config"
    }
  ]
}
```

---

## Schema Information

These tools help AI assistants understand your content architecture—the sections, fields, asset volumes, and plugins that define how your Craft installation is structured.

### list_plugins

List all installed plugins with their version numbers, enabled status, and developer information. Useful for understanding what functionality has been added to a Craft installation.

**Parameters:** None

**Response includes:** Plugin handle, name, version, enabled status, and developer info.

---

### list_sections

Get all sections and their entry types. This reveals the content structure of your site—which sections exist, whether they're channels, structures, or singles, and what entry types are available in each.

**Parameters:** None

**Response includes:** Section handle, name, type (single/channel/structure), and entry types with their field layouts.

---

### list_fields

List all custom fields defined in your Craft installation. Each field includes its type (Plain Text, Matrix, Entries, etc.), the field group it belongs to, and relevant settings.

**Parameters:** None

**Response includes:** Field handle, name, type, group assignment, and field-specific settings.

---

### list_volumes

Get information about asset volumes—where files are stored and how they're accessed. This includes filesystem configuration and public URL settings.

**Parameters:** None

**Response includes:** Volume handle, name, filesystem type, and base URL.
