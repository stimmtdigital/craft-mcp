# Debugging Tools

Debugging tools help AI assistants investigate issues in your Craft installation. From queue job failures to project config drift to deprecation warnings, these tools surface the diagnostic information needed to understand what's happening—and what's gone wrong.

## Queue Jobs

Craft's queue system handles background tasks like image transforms, element resaving, and plugin operations. When things slow down or fail silently, the queue is often the culprit.

### get_queue_jobs

List queue jobs filtered by their current status. This tool helps you understand what's waiting to run, what's currently processing, and what has failed.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `status` | string | "pending" | Filter jobs by status |
| `limit` | int | 50 | Maximum number of jobs to return |

**Available status filters:**

| Status | Description |
|--------|-------------|
| `pending` | Jobs waiting in queue to be processed |
| `reserved` | Jobs currently being executed |
| `failed` | Jobs that encountered errors and couldn't complete |
| `done` | Returns a count of completed jobs (not individual jobs) |

**Examples:**

```
# Check what's waiting to run
get_queue_jobs status="pending"

# See what failed and why
get_queue_jobs status="failed" limit=10

# Check if anything is currently running
get_queue_jobs status="reserved"
```

**Response:**

```json
{
  "status": "failed",
  "count": 2,
  "counts": {
    "pending": 5,
    "reserved": 1,
    "failed": 2
  },
  "jobs": [
    {
      "id": 123,
      "jobClass": "craft\\queue\\jobs\\ResaveElements",
      "description": "Resaving entries",
      "timePushed": "2024-01-15 10:00:00",
      "timeUpdated": "2024-01-15 10:01:30",
      "attempt": 3,
      "error": "Memory limit exceeded"
    }
  ]
}
```

The `counts` object in every response gives you a quick overview of the entire queue state, regardless of which status you filtered by. This makes it easy to spot problems—if you see failed jobs piling up, something needs attention.

---

## Project Config

Craft's Project Config system stores your site's structure (sections, fields, volumes, etc.) in YAML files for version control. Sometimes the database and YAML files get out of sync, leading to confusing behavior.

### get_project_config_diff

Check whether your database matches the YAML files in `config/project/`. This tool shows pending changes that would be applied when running `php craft project-config/apply`.

**Parameters:** None

**Example:**

```
get_project_config_diff
```

**Response when everything is in sync:**

```json
{
  "pending": false,
  "message": "Project config is up to date"
}
```

**Response when changes are pending:**

```json
{
  "pending": true,
  "summary": {
    "added": 2,
    "removed": 0,
    "modified": 3
  },
  "changes": {
    "added": ["sections.newSection", "fields.newField"],
    "removed": [],
    "modified": ["sections.blog", "fields.summary", "plugins.seo"]
  },
  "hint": "Run `php craft project-config/apply` to apply changes"
}
```

This is invaluable after pulling changes from version control. If your local database doesn't match what's in the YAML files, you'll see exactly what would change before applying the config.

---

## Deprecations

Deprecation warnings tell you that code is using features scheduled for removal in future Craft versions. Addressing these warnings before upgrading prevents breaking changes.

### get_deprecations

Gather deprecation warnings from both the database (where Craft stores persistent deprecation notices) and the log files. This gives you a complete picture of what needs updating.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `limit` | int | 50 | Maximum entries to return from each source |

**Example:**

```
get_deprecations
get_deprecations limit=20
```

**Response:**

```json
{
  "fromDatabase": {
    "count": 5,
    "deprecations": [
      {
        "id": 1,
        "key": "craft.app.config",
        "lastOccurrence": "2024-01-15 10:30:00",
        "file": "templates/index.twig",
        "line": 15,
        "message": "craft.app.config is deprecated. Use craft.app.config.general instead.",
        "template": "templates/index.twig"
      }
    ]
  },
  "fromLogs": {
    "count": 3,
    "deprecations": [
      {
        "timestamp": "2024-01-15 09:00:00",
        "level": "warning",
        "category": "deprecation",
        "message": "..."
      }
    ]
  },
  "hint": "Database deprecations persist until fixed. Clear with `php craft clear-deprecations`."
}
```

Database deprecations are particularly useful because they include the exact file and line number where the deprecated code was called. This makes fixing them straightforward—you know exactly where to look.

---

## Query Performance

Slow database queries can make your site feel sluggish. The explain tool helps identify why queries are slow and how to optimize them.

### explain_query

Run MySQL or PostgreSQL's `EXPLAIN` command on a SELECT query to understand how the database will execute it. This reveals whether indexes are being used, how many rows will be scanned, and where bottlenecks might occur.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `sql` | string | Yes | The SELECT query to analyze |

**Security:** This tool has the same restrictions as `run_query`—only SELECT statements are allowed, and dangerous keywords are blocked.

**Example:**

```
explain_query sql="SELECT * FROM craft_entries WHERE sectionId = 1"
```

**Response (MySQL):**

```json
{
  "success": true,
  "driver": "mysql",
  "query": "SELECT * FROM craft_entries WHERE sectionId = 1",
  "explain": [
    {
      "id": 1,
      "select_type": "SIMPLE",
      "table": "craft_entries",
      "type": "ref",
      "possible_keys": "idx_entries_sectionId",
      "key": "idx_entries_sectionId",
      "rows": 45,
      "Extra": "Using where"
    }
  ],
  "warnings": []
}
```

Key things to look for in the output:

- **type**: "ALL" means a full table scan (usually bad). "ref" or "const" means an index is being used (good).
- **key**: Shows which index is being used. If this is null, no index matched the query.
- **rows**: Estimated number of rows the database will examine. High numbers suggest missing indexes.

---

## Environment

Environment settings affect how Craft behaves—dev mode, queue settings, caching, and more. This tool surfaces those settings without exposing sensitive values like database credentials.

### get_environment

Get a comprehensive overview of your environment configuration, including PHP settings, Craft system settings, and important paths. Sensitive values like passwords and API keys are automatically excluded.

**Parameters:** None

**Example:**

```
get_environment
```

**Response:**

```json
{
  "environment": {
    "CRAFT_ENVIRONMENT": "dev",
    "CRAFT_DEV_MODE": "true",
    "CRAFT_ALLOW_ADMIN_CHANGES": "true",
    "CRAFT_RUN_QUEUE_AUTOMATICALLY": "true"
  },
  "php": {
    "memory_limit": "256M",
    "max_execution_time": "120",
    "display_errors": "1",
    "upload_max_filesize": "128M",
    "post_max_size": "128M",
    "opcache.enable": "1"
  },
  "system": {
    "isSystemLive": true,
    "devMode": true,
    "cpTrigger": "admin",
    "runQueueAutomatically": true,
    "allowAdminChanges": true,
    "enableTemplateCaching": false
  },
  "paths": {
    "basePath": "/var/www/html",
    "configPath": "/var/www/html/config",
    "storagePath": "/var/www/html/storage",
    "templatesPath": "/var/www/html/templates",
    "translationsPath": "/var/www/html/translations"
  }
}
```

This tool is particularly useful for debugging issues that might be environment-specific—like why template caching works in production but not locally, or why file uploads are failing due to PHP limits.

---

## Event Handlers

Plugins and modules often hook into Craft's event system to add custom behavior. When debugging unexpected behavior, understanding what event handlers are registered can reveal where custom code might be interfering.

### list_event_handlers

List all registered event handlers in the current application. You can filter by event name or class to narrow down the results.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `filter` | string | null | Filter results by event or class name |

**Examples:**

```
# List all registered handlers
list_event_handlers

# Find handlers related to entries
list_event_handlers filter="Entry"

# Find handlers for a specific event
list_event_handlers filter="EVENT_BEFORE_SAVE"
```

**Response:**

```json
{
  "applicationEvents": {
    "count": 5,
    "events": {
      "beforeRequest": {
        "count": 2,
        "handlers": ["MyModule::handleRequest()", "Closure in Plugin.php:45"]
      }
    }
  },
  "classEvents": {
    "count": 12,
    "events": {
      "craft\\elements\\Entry::EVENT_BEFORE_SAVE": {
        "class": "craft\\elements\\Entry",
        "event": "EVENT_BEFORE_SAVE",
        "count": 3,
        "handlers": [
          "myplugin\\MyPlugin::onEntrySave()",
          "Closure in module.php:123"
        ]
      }
    }
  },
  "hint": "Use filter parameter to search by event or class name"
}
```

If entries aren't saving correctly, or assets are behaving strangely, checking the event handlers for those elements can point you toward custom code that might be causing the issue.

---

## PHP Execution

Sometimes you need to run arbitrary PHP code to debug an issue or inspect application state. The tinker tool provides this capability within Craft's application context.

### tinker

Execute PHP code within your Craft application context. This is a powerful tool for debugging, inspection, and quick one-off operations. It's classified as dangerous and can be disabled via configuration.

> **Note:** This tool can be disabled via configuration. See the [Configuration Guide](../configuration.md).

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `code` | string | Yes | PHP code to execute |

**Security restrictions:**

For safety, certain operations are blocked:

- **Shell commands**: `exec`, `shell_exec`, `system`, `passthru`, `proc_open`
- **File writes**: `file_put_contents`, `fwrite`, `fputs`
- **Dangerous functions**: `eval` (to prevent nested evaluation)

The code is parsed using PsySH's CodeCleaner before execution.

**Examples:**

```
# Get the Craft version
tinker code="Craft::$app->getVersion()"

# Count entries in a section
tinker code="\craft\elements\Entry::find()->section('news')->count()"

# List all registered application components
tinker code="return array_keys(Craft::$app->getComponents())"

# Check a specific service
tinker code="return Craft::$app->getEntries()->getSectionByHandle('blog')?->name"
```

**Response:**

```json
{
  "success": true,
  "result": "5.0.0",
  "output": null,
  "type": "string"
}
```

**Error response:**

```json
{
  "success": false,
  "error": "Code contains blocked function for security."
}
```

**Available in context:**

Your code has access to:

- `$app` - A convenient alias for `Craft::$app`
- All Craft classes and services
- Full Composer autoloading

This tool is incredibly useful for debugging complex issues where you need to inspect application state, test queries, or verify service behavior without writing a dedicated controller action.
