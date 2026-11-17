# Operations Tools

Install health and operations: whether an install is OK and what to do if it is not, which errors keep coming back, which queue jobs died, which requests are slow, which installed packages have known security advisories, and the figures for a periodic maintenance report.

Craft's own MCP server has tools for the queue (`queue_*`), project config (`project_config_*`) and system info, but nothing that judges an install as a whole, groups errors by cause or reads the failed jobs' errors. These tools do, and they only read.

All of these are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Lite edition, except `stimmt_maintenance_report`, which is Pro. None is dangerous.

| Tool | Scope | Edition |
|------|-------|---------|
| [`stimmt_health`](#stimmt_health) | admin | Lite |
| [`stimmt_errors_summary`](#stimmt_errors_summary) | admin | Lite |
| [`stimmt_queue_failures`](#stimmt_queue_failures) | admin | Lite |
| [`stimmt_requests_slow`](#stimmt_requests_slow) | admin | Lite |
| [`stimmt_dependencies_audit`](#stimmt_dependencies_audit) | admin | Lite |
| [`stimmt_maintenance_report`](#stimmt_maintenance_report) | admin | Pro |

Each of these except `stimmt_health`, whose output is a fixed set of checks, refuses a response larger than `maxResponseBytes` (see [Configuration](../configuration.md)) and asks for a narrower call instead.

---

## stimmt_health

One call that answers "is this install OK, and if not, what do I do". No parameters.

Example question: *"Is anything wrong with the site?"* or *"Is production healthy after the deploy?"*

It checks, area by area:

| Area | Checks |
|------|--------|
| `mcp` | The same checks as `php craft mcp/check`: Craft's MCP server, endpoint path, login, OAuth keys, plugin tools, edition, leftover `config/mcp.php` |
| `environment` | Dev mode (an error when on and `CRAFT_ENVIRONMENT` is exactly `production`), admin changes allowed in production (a warning), PHP against Craft's own PHP requirement, database driver and version (reported, not judged) |
| `updates` | Pending migrations, Craft schema older than the database, available and critical updates from Craft's **cached** update info |
| `projectConfig` | Schema version mismatches between `config/project` and what is installed, and YAML changes not yet applied |
| `queue` | Failed jobs, and waiting jobs whose oldest has been due for more than an hour (3600 seconds), which means nothing is running the queue |
| `storage` | Whether the storage and runtime directories are writable, and free disk space: a warning below 1 GB, an error below 200 MB |
| `errors` | Errors logged in the last 24 hours |

Returns:

```json
{
  "checks": [
    {
      "area": "queue",
      "label": "Failed jobs",
      "level": "error",
      "message": "3 queue job(s) failed and will not run again on their own.",
      "fix": "Read why with stimmt_queue_failures, fix the cause, then retry them with Craft's queue_retry_all."
    }
  ],
  "summary": { "ok": 14, "info": 3, "warning": 1, "error": 1 }
}
```

- `checks` come errors first, then warnings, info and ok. Within a level they keep the area order above.
- `level` is `error`, `warning`, `info` or `ok`. `fix` is present only on a check where something needs doing.
- `summary` always has all four levels, zeros included.

It never contacts Craft's update server: without cached update info, "Available updates" reads `info` with "Could not determine" and a pointer to **Utilities → Updates**. It does not regenerate project config YAML. An area that fails to read becomes one `info` check naming the exception's class only, never its message, because a failed connection message can quote a DSN or credential.

---

## stimmt_errors_summary

Errors from Craft's logs over the last N hours, grouped by what kind of error they are. Use it to answer "what keeps breaking"; use [`stimmt_logs_read`](system.md#stimmt_logs_read) for the raw entries.

Example question: *"Which errors keep happening on the site this week?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `hours` | int | 24 | How many hours back to look, 1 to 720 |
| `source` | string | null | `web`, `console`, `queue`, or a plugin name (a log file name prefix). Omit to read every log file |
| `limit` | int | 20 | How many groups to return, 1 or more |

Errors are grouped by exception class, message with ids, numbers, UUIDs, hex values, path segments and quoted values masked, and the first stack frame in project code. Craft's separate "Request context" log entry is not counted as an error.

Returns the list envelope from the [Tools Reference](README.md#response-shape) (without `offset`), plus:

| Key | Meaning |
|-----|---------|
| `hours` | The window read |
| `totalErrors` | Error entries in the window |
| `scannedFiles` | The log files read, relative to the log directory |
| `complete` | `false` when the scan cap filled before the start of the window was reached. Then every count is a floor, not a total |
| `note` | The scan bounds, stated in the response |
| `groups` | Most frequent first, then most recent |

Each group:

| Key | Meaning |
|-----|---------|
| `kind` | The exception class, or `message` for an entry that names none |
| `message` | The most recent occurrence's message, cut at 300 characters |
| `count` | Occurrences in the window |
| `firstSeen`, `lastSeen` | ISO 8601, in the install's time zone |
| `sources` | The logs it appeared in (`web`, `console`, `queue`, ...) |
| `example` | `site`: where in project code it was thrown; `trace`: up to 8 stack frames |

Bounds: at most 500 error entries are read, and each log file back at most 50000 lines or 4 MB. Over HTTP the tool sends a progress notification per log file.

The grouping masks values; the `message` and trace of the example are the real ones from the log, as `stimmt_logs_read` would show them.

---

## stimmt_queue_failures

Failed jobs in Craft's database queue, most recently failed first, with the error each one failed with.

Example question: *"Why didn't the search index update?"* or *"Which queue jobs failed?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `limit` | int | 20 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip, 0 or more |

Returns the paginated envelope with `jobs` and a `hint`. Each job:

| Key | Meaning |
|-----|---------|
| `id` | Queue job id |
| `class` | The job's class, or `null` when it could not be read |
| `description` | The job's description |
| `attempt` | Attempts made |
| `timePushed`, `failedAt` | ISO 8601 |
| `error` | The stored error, cut at 1000 characters |

The job payload (the serialized job) is never returned: it can hold whatever a plugin pushed, such as an API payload, a token or an address.

The tool only reads. To retry, fix the cause first, then use Craft's own `queue_retry_all` and `queue_run` (or let the queue worker pick the jobs up). An install whose queue is not Craft's database queue (Redis, SQS and other drivers) is refused with the driver's name: only the database queue keeps failed jobs where they can be read.

---

## stimmt_requests_slow

The slowest recent requests this server recorded, slowest first, with the statements each request repeated most.

Example question: *"Why is the news overview page slow?"* or *"Which pages run the most queries?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `limit` | int | 10 | Requests to return, 1 to 50 |
| `minMs` | float | null | Leave out requests faster than this many milliseconds. Requests whose duration was not recorded are left out too when this is set |

The data comes from the Yii debug module, which Craft records only while the debug toolbar is on: an admin's **Show the debug toolbar** account preference, or Dev Mode with an `X-Debug: enable` request header. It covers only the requests made with it on, on this server, so it suits a local or development install where someone has loaded the pages to measure. A `dataPath` set in `config/debug.php` is honoured; an `fs` setting there (debug data on a Craft filesystem) is refused, because the tool reads the local disk only.

Returns the list envelope (without `offset`), plus `recorded` (requests in the debug index) and `requests`:

| Key | Meaning |
|-----|---------|
| `tag` | The debug module's id for the request |
| `url` | Scheme, host, port and path. For a console run, the first three words of the command only |
| `method`, `statusCode` | As recorded |
| `durationMs`, `memoryMb`, `queryCount` | `null` when the module recorded nothing, which is not the same as zero |
| `duplicateQueries` | Up to 5 statements run more than once in that request, most repeated first, as `{sql, count}`. SQL has literals replaced by `?` and is cut at 300 characters. `null` when the request's data file is gone or has no database panel |
| `time` | ISO 8601 |

Never returned: query strings, request parameters, headers, cookies, the client IP, or bound values inside SQL. A query string or a bound value can carry a session id, a reset token or a password hash.

When no debug data exists, the call fails with how to turn recording on.

---

## stimmt_dependencies_audit

Known security advisories for the Composer packages this project installed, like `composer audit`.

Example question: *"Do any of our installed packages have known security issues?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `includeDev` | bool | false | Also check `packages-dev` from `composer.lock`. The default checks what a production install runs |

> Calls out: this tool sends the list of installed package **names** (not their versions) to packagist.org's public security-advisory API. Matching the versions happens on your server. It carries the `openWorldHint` annotation for that reason.

Installed versions are read from `composer.lock` in the project root; a missing or invalid lock file is refused with how to create one. The request to packagist.org times out after 10 seconds. If packagist.org cannot be reached or answers with something unexpected, the call fails rather than answering with an empty list. Proxy credentials in a connection error are masked.

Returns:

| Key | Meaning |
|-----|---------|
| `count` | Advisories that affect an installed version |
| `checked` | Packages checked |
| `source` | `packagist` |
| `includeDev` | As requested |
| `advisories` | Each: `package`, `installedVersion`, `advisoryId`, `cve`, `title`, `link`, `severity`, `affectedVersions`, `reportedAt` |
| `uncovered` | Package names Packagist sent no data for (private packages, forks). They were **not checked**, which is not the same as safe |
| `undecided` | Advisories whose version range or installed version Composer's parser could not read: neither affected nor clear |

---

## stimmt_maintenance_report

The figures for a periodic maintenance report on one install, in one call. It reuses `stimmt_health` and `stimmt_errors_summary` and adds content changes per section and per editor.

Example question: *"Write the monthly maintenance report for this site."*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `days` | int | 30 | The period, in days back from now, 1 to 90 |

Returns:

| Key | Content |
|-----|---------|
| `period` | `from`, `to` (ISO 8601) and `days` |
| `health` | `summary` (counts per level) and `problems`: every health check whose level is not `ok`, with its `fix`. This includes `info` checks, and is where available or critical updates and queue problems show up |
| `errors` | `windowDays`, `total`, `complete`, and `top`: up to 5 error groups, each `kind`, `message`, `count`, `lastSeen` |
| `content` | `saves` (entry saves in the period), `created` (first versions), `bySection` (`section`, `saves`, `created`) and `byEditor` (`editor`, `saves`), most saves first |

Bounds and limits:

- The error part reads back at most 30 days (720 hours). A longer report says so in `errors.windowDays` rather than failing.
- Content changes are counted from Craft's entry revisions, so sections with versioning off and nested Matrix entries are not counted. Saves without a recorded user (console scripts, migrations) count under `(system)`; saves by a since-deleted user under `(deleted user)`.
- More than 50000 entry saves in the period is refused with a request for fewer days.

The output is raw figures. Summarise it for a person; do not paste it whole.
