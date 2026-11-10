# Debugging Tools

Diagnose an install. All of these are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Lite edition.

Query plans are in [Database Tools](database.md#stimmt_db_explain); logs and the last error are in [System Tools](system.md).

---

## stimmt_deprecations_list

Deprecation warnings, from Craft's `deprecationerrors` table and from the log files.

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `limit` | int | 50 | Warnings to return from each source, 1 or more |

Returns `fromDatabase` and `fromLogs`, each with the list envelope from the [Tools Reference](README.md#response-shape). Database entries stay until cleared with `php craft clear-deprecations`.

---

## stimmt_environment_get

Environment information without secrets. No parameters.

| Key | Content |
|-----|---------|
| `environment` | `CRAFT_ENVIRONMENT`, `CRAFT_DEV_MODE`, `CRAFT_ALLOW_ADMIN_CHANGES`, `CRAFT_RUN_QUEUE_AUTOMATICALLY` |
| `php` | `memory_limit`, `max_execution_time`, `display_errors`, `error_reporting`, `upload_max_filesize`, `post_max_size` |
| `system` | Whether the system is live, `devMode`, `cpTrigger`, `runQueueAutomatically`, `allowAdminChanges`, `enableTemplateCaching`, `testToEmailAddress` |
| `paths` | Base, config, storage and templates paths |

---

## stimmt_event_handlers_list

List registered event handlers, to see what code runs on an event.

| Name | Type | Description |
|------|------|-------------|
| `filter` | string | Case-insensitive text matched against event names and the classes the events are registered on |

Returns `applicationEvents` and `classEvents`, each with a `count`.

---

## stimmt_tinker

> Dangerous: hidden while `enableDangerousTools` is off, which is the default in production.

Run PHP in Craft's context, with access to `Craft::$app` and every service. Use a specific tool when one exists; this is for jobs no tool can express, such as a computation across entries.

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `code` | string | | PHP statements. `$app` is bound to `Craft::$app`. The returned value is rendered, and anything echoed is captured and returned with it |
| `output` | string | `dump` | How the return value is rendered: `dump`, `json`, `raw` or `print_r` |

The code is checked against a blocklist, which is not a sandbox. Development use only.
