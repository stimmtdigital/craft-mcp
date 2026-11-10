# System Tools

Read configuration, logs and routes. All of these are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Lite edition.

Craft's own tools cover system info (`info_get`), plugins (`plugins_*`), caches (`caches_*`), the queue (`queue_*`) and project config (`project_config_*`).

---

## stimmt_config_get

Read a Craft config value by dot-notation key.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `key` | string | Yes | Such as `general.devMode` or `db.driver`. A bare category (`general`, `db`) returns every setting in it; `custom.<file>` reads a file from `config/` |

Returns `key` and `value`. Credentials such as the security key and the database password come back as a redaction placeholder: named, not revealed. An unknown category is refused with the ones that exist.

---

## stimmt_logs_read

Read recent entries from Craft's log files, newest first.

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `limit` | int | 50 | Entries to return, 1 or more |
| `level` | string | null | Exact level to keep, as the log line spells it (`error`, `warning`, `info`, `trace`). Not a minimum: `warning` excludes errors |
| `pattern` | string | null | Case-insensitive text the message must contain |
| `source` | string | null | `web`, `console`, `queue`, or a plugin name. Omit to read every log file |

The search walks each log backwards until it has `limit` matches, so `limit` bounds the entries returned, not the lines searched. The response has no `total`. A response larger than `maxResponseBytes` is refused with a request to narrow the call (see [Configuration](../configuration.md)).

---

## stimmt_errors_last

The most recent error in Craft's log files. No parameters.

Returns `found: true` and `error`, or `found: false` with how far back each file was searched.

---

## stimmt_console_commands_list

List the console commands available as `php craft <command>`. No parameters.

Over stdio only. Over HTTP, Craft loads web controllers instead of console ones, so the tool answers with `unavailable` and a reason rather than an empty list.

---

## stimmt_routes_list

List the routes registered in Craft. No parameters.
