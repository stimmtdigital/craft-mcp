# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Text output for tool payloads: `run_query`, `get_table_counts`, and `count_entries` accept `output="text"` and return the same data laid out for a person (uniform rows as an aligned table, key-value data and breakdowns as aligned blocks, nesting indented), falling back to pretty JSON for anything that will not lay out cleanly. A tool opts in purely by declaring the parameter in its signature, and the rendering happens centrally, so no tool carries formatting code of its own
- `colorOutput` config setting: whether human-readable output carries ANSI colour. Off by default, because escape sequences reach an AI client as literal noise it pays tokens for

### Changed
- `read_logs` with `output="text"` is no longer coloured unconditionally; its layout is unchanged and `colorOutput` restores the colours. Colour is now decided in exactly one place instead of per formatter

### Removed
- Undocumented introspection helpers with no callers anywhere: `Mcp::collectExternalTools()`, `collectExternalPrompts()` and `collectExternalResources()` (deprecated in favour of the registries), `getAllToolClasses()` and its prompt and resource twins on the registration events, `ToolRegistry::getToolClasses()` and `getToolsBySource()`, `PromptRegistry::getPromptClasses()`, `ResourceRegistry::getResourceClasses()`, and the six copies of `getDefinitionsByCategory()`. None appear in `docs/extending.md`, so none were part of the documented extension API. `getDefinitionsBySource()`, which is used, stays

### Fixed
- An external tool declaring `#[McpTool(description: ...)]` without a `name:` registered under the empty string. The SDK falls back to the method name and so do our prompt and resource events; only the tool event did not, so such a tool reached `tools/list` unnamed, uncallable, and colliding with any other tool that made the same omission
- An attribute subclassing `McpTool`, `McpPrompt` or `McpResource` is discovered again. Every SDK lookup passes `IS_INSTANCEOF` and none of our fourteen did, so the SDK registered such a tool and our own deny-by-default sweep then removed it, leaving only a debug-level trace. Subclassing is legal and is a normal way to carry house metadata
- A tool class implementing `ConditionalProvider` had its `isAvailable()` ignored, because tool registration tested the deprecated `ConditionalToolProvider` subinterface while prompt and resource registration tested the base one. All three now agree; the deprecated interface keeps working, since it extends the base
- `query_graphql` and `execute_graphql` work over stdio. Craft's GraphQL execution is console-safe on its own, but it fires events, and a plugin listening on one of them may ask the current request for headers the way it would in a web context. Over stdio that request is a console request, which has no such method, so both tools died with an unhelpful "Calling unknown method" and the agent was told nothing it could act on. The console request now carries a behaviour answering that one question with an empty header collection, which is the honest answer and the same branch a listener takes on a web request that sent no header. Craft's own console checks are unaffected
- Tools that take no arguments no longer fail with `-32603 Error while executing tool`. Nineteen tools on a stock install take none, and the SDK hands their schema over with the property map as an empty JSON object rather than an array; the output layer read it as an array and died on every call, taking `reload_mcp`, `get_mcp_info`, `list_sites` and `list_plugins` with it
- Logged exceptions are readable. A `Throwable` carries no public properties, so encoding the conventional `['exception' => $e]` context turned every one of them into `{}`, and every error this server had ever logged was undiagnosable. The context is now normalized first: class, message, code, file, line and a trimmed stack trace, along the whole `previous` chain, bounded so that one entry still occupies one line and a deep trace cannot flood the file
- Cached tool discovery invalidates itself when the scanned code changes. Editing a tool, adding a tool class or switching branches kept `tools/list` serving the previous scan, and neither reconnecting the client nor restarting the container helped, because the staleness lives in Craft's cache rather than in the process. The cache key now carries the state of the code it was built from: a stat-based fingerprint of the plugin source when `devMode` is on, and the plugin version otherwise, so production keeps a stable key and does no filesystem work per request
- Every tool payload is sent once instead of twice. The SDK serialized array returns into both `content` and `structuredContent`, doubling the wire and token cost of every call; no tool declares an output schema, so the duplicate carried nothing a client could use

## [1.4.0-beta.12] - 2026-08-06

### Fixed
- `disabledScopes` is now enforced at authentication too: a token minted before its scope was disabled stops working (rejected exactly like an unknown token, leaving no trace on the token) until the scope is re-enabled, which restores it without recreation. Previously the setting only gated new minting, so a pre-existing token of a disabled scope kept working, exactly the loophole the guardrail exists to close, as argued in #56 (#48, #56)

## [1.4.0-beta.11] - 2026-08-06

### Added
- `showClientConfigSnippet` config setting: when off, the ready-to-paste Claude Desktop config block is not built or shown on the control panel token-reveal screen, and the `mcp/tokens/create` console command prints the plaintext token without it; the setting is consulted in exactly one place, so the two surfaces can never disagree (#62)

### Fixed
- Entries created by `create_entry` in draft mode now land as saved drafts, visible in the control panel Entries list, search, and the Drafts status filter for every user with access, exactly where a human's "+ New entry" then "Save draft" lands. Previously they stayed in Craft's "still being composed" autosave state forever: invisible to reviewers everywhere except the creator's own dashboard widget, and subject to Craft's stale unsaved-draft garbage collection. They remain unpublished drafts either way (no public URL until `publish_entry`). Drafts created by earlier versions stay in the old state; a no-op `update_entry` on such a draft's element id flips it to saved with no content changes (#61)

## [1.4.0-beta.10] - 2026-08-06

### Added
- `disabledScopes` config setting: block minting specific token scopes per environment (for example `'production' => ['disabledScopes' => ['full']]`), enforced for everyone including admins, in the control panel dropdown, the control panel create action, and the console command through one shared predicate; existing tokens of a disabled scope keep working and keep regenerating (#48)
- `additionalInstructions` config setting: install-owner text appended to the end of the server instructions on every transport, for deployment-specific guidance an agent must see before its first write (house style guides, staging warnings, approval workflows); empty by default (#52)

### Changed
- `craftcms/cms` requirement raised to `^5.1`: the control panel token screens use `EditUserTrait` (since Craft 5.1), which is a compile-time fatal on Craft 5.0 (#51)
- CI now tests PHP 8.3, 8.4, and 8.5, PHPStan analyses against that full version range, and lefthook pre-commit/pre-push hooks wire themselves via `composer install` for contributors; `rector/rector` floats at `^2.5` so it tracks PHPStan releases

### Fixed
- Tools registered by SDK attribute discovery without being available (for example the Commerce tools when Craft Commerce is not installed) no longer bypass `disabledTools`, scope, and the privileged axis: anything without an informational definition is unregistered deny-by-default, and `get_mcp_info`'s `bySource` counts now sum to its `total` (#57)
- `disabledPrompts` and `disabledResources` are now actually enforced at serve time, with the same deny-by-default sweep as tools; they were documented but never applied. The content-writing guide resource is also registered in the informational registry, where it had been missing
- `craft://entries/{section}/stats` is reachable again: it registered after the `{section}/{slug}` template, whose variable segment swallowed the literal `stats` (#49)
- `describe_entry_schema` expands each Matrix field once instead of twice: `input.blockTypes` is now a flat map of the valid block-type handles and the field's top-level `blockTypes` list is the single depth-expanded representation, keeping per-field `instructions`; nested Matrix fields at exhausted depth now name their block types too (#53)
- The server instructions no longer recommend tools a connection cannot call: a computed availability note lists any cited-but-disabled tools, and the full-scope wording is qualified to what the install exposes (#50)
- The control panel no longer offers the Full scope to token managers who are not admins only to reject it on submit: the dropdown and the create authorization consult the same predicate (#48)

## [1.4.0-beta.9] - 2026-07-20

### Changed
- Upgraded `mcp/sdk` from `^0.6` to `^0.7`. Scope-based tool filtering keeps working under the SDK's new deferred element loading, since a registry supplied via `setRegistry()` is loaded eagerly at build time; a test pins that contract so a future SDK change that breaks it fails loudly.
- Upgraded `rector/rector` from `2.5.5` to `2.5.7`.

## [1.4.0-beta.8] - 2026-07-20

### Fixed
- The HTTP endpoint now works on installs that serve the control panel from the root of its domain (`cpTrigger` null): the endpoint registers as a CP URL rule there, since such hosts never consult site URL rules. Craft intercepts the default `mcp` path for guests on those hosts (it matches the plugin handle, a Craft core behavior that runs before routing), so a different `httpPath` (for example `mcp-http`) is required and the new "Control panel on the root domain" section in the HTTP transport guide explains the setup.

## [1.4.0-beta.7] - 2026-07-17

### Added
- Manage MCP tokens from the control panel: an "MCP Tokens" screen on My Account (mint readonly/content tokens for yourself, view, regenerate, and revoke your own), the same screen on other users' pages for token managers, and a Utilities panel listing every token. New tokens reveal once in a modal with copy and download buttons; regenerate re-issues a token in place (fresh secret, same name/scope/user/expiry, old one invalidated). Gated by two new user permissions; visible only when `httpTransport` is enabled.
- readonly and content HTTP tokens now read only what their linked Craft user may view: entries, assets, and categories are filtered to the sections, volumes, and groups the user can view, and user listing requires the View Users permission
- Install-introspection and unbounded-read tools (read_logs, get_last_error, get_config, get_database_schema/info, get_table_counts, get_project_config_diff, get_environment, query_graphql, list_graphql_tokens, list_globals, list_backups, and the Commerce order/product reads) are locked to admins over read/content-scope tokens; open specific ones with the new `scopedTokenPrivilegedTools` config setting
- `paginationLimit` setting for the MCP list page size, defaulting to 100 so a single unpaginated `tools/list` call sees every registered tool (#40)
- HTTP transport sessions are stored in the database (new `mcp_sessions` table), so they survive load-balanced, multi-instance hosting; the new `httpSessionStore` setting accepts a class name or callable for custom stores such as Redis (#41)
- Attribute discovery is cached through Craft's cache component, so HTTP requests skip the full per-request reflection scan; `reload_mcp` invalidates the cache
- The tinker, database, and debug tools stream diagnostics to the client at the log level it negotiates via `logging/setLevel`
- Resource subscriptions now deliver: subscribed clients receive `resources/updated` when a live write or publish changes an entry, or when an external project config change refreshes the config resources, and `reload_mcp` pushes `tools/list_changed`
- The tool registry is wired to a real event dispatcher, so the list-changed server capabilities advertise truthfully
- Documented that Matrix-family blocks are directly addressable by their own id in `update_entry`, `delete_entry`, and `publish_entry` (#39)

### Fixed
- Long-lived MCP processes now pick up custom fields added by another process (for example `project-config/apply`): the config freshness probe patches the compiled `CustomFieldBehavior` handle map and flushes the GraphQL schema caches instead of leaving the process stale until restart
- MCP resource reads now respect the same authorization as tools: entry resources (`craft://entries/...`) are bounded by the acting user's view permissions and config resources (`craft://config/...`) are admin-only over readonly and content-scope tokens, closing a read path that previously bypassed the tool-surface guarantees entirely

## [1.4.0-beta.6] - 2026-07-15

### Added
- Content-scope HTTP tokens now respect the linked user's real Craft permissions on entry writes (create, update, publish, delete, duplicate, copy to site), checked live per request through Craft's element authorization (#34)
- Publishing mirrors the control panel's apply-draft gate: the acting user must be allowed to save the draft itself (own draft or peer-draft permission) and its canonical version, on both the draft-id and canonical-id publish routes
- Drafts created over the HTTP transport are attributed to the token's user, so editors can publish their own drafts without peer-draft permissions

## [1.4.0-beta.5] - 2026-07-14

### Added
- `count_entries` tool: totals and per-value breakdowns (attribute, date bucket, or field-value grouping) with the same filters as `list_entries`
- Field-value `filters`, `relatedTo`, `author`, and date-range parameters on `list_entries`, with natural keys and `:empty:`/`:notempty:` support
- `fields` projection on `list_entries` for slim rows when scanning many entries
- `list_revisions` tool: an entry's saved history (who, when, notes); `get_entry` now reads revision ids
- `query_graphql` tool: read-only GraphQL queries (mutations rejected at parse time), not gated by `enableDangerousTools`
- Read-only and idempotent annotations on every non-dangerous tool (reload_mcp exempt)
- Tool-selection ladder in the server instructions, prompt guides, and the `tinker`/`run_query` descriptions

## [1.4.0-beta.4] - 2026-07-10

### Fixed
- Production safety defaults (`enabled` and `enableDangerousTools` off) now apply BEFORE the config file is read instead of only when no config file exists. Previously, creating `config/mcp.php` (as every quick start instructs) silently re-enabled dangerous tools in production; now only an explicit `'enableDangerousTools' => true` in the file does that.
- `config/mcp.php` now supports Craft's multi-environment config convention (a `'*'` base merged with the current environment's block), which the configuration guide documented but the loader ignored.

### Added
- `list_drafts` tool: the review queue for the draft-first workflow. Lists pending (non-provisional) entry drafts newest first, filterable by section, site, or creator, with the draft element id, canonical id, draft notes, and a control panel deep link per row. Available to `readonly` HTTP tokens, since reviewing is reading.
- `review_pending_drafts` prompt: walks the queue conversationally (inspect via `get_entry`, approve via `publish_entry`, reject via `delete_entry`).

## [1.4.0-beta.3] - 2026-07-10

### Changed
- Agent guidance now teaches the content-writing upgrade everywhere it speaks: the server instructions walk through schema discovery, natural keys, and the draft-first flow; the `create_entry_guide` and `bulk_entry_operations` prompts stop inviting payload guessing and lean on `describe_entry_schema` input shapes and drafts-as-safety-net; and a new `craft://guides/content-writing` resource serves the full payload contract on demand. HTTP connections additionally get a per-scope note in their instructions stating what the presenting token can and cannot do, and the `debug_content_issue` prompt stops recommending a tool that does not exist.

## [1.4.0-beta.2] - 2026-07-09

### Fixed
- The long-lived stdio server now detects external project config changes (migrations, `project-config/apply`, control panel edits) before each tool call and refreshes itself, ending `StaleResourceException` on writes and silently stale schema reads across the whole read surface (fields and layouts, sections and entry types, sites, volumes, category groups, global sets). No more manual `reload_mcp` after running migrations. Thanks @dgaidula for the report and groundwork in #18.

## [1.4.0-beta.1] - 2026-07-09

### Added
- `elements` module: element-generic payload engine (natural keys for relations, draft-first writes, schema discovery), reusable outside the plugin (imports only craftcms/cms and psr/log, enforced by an architecture test)
- `describe_entry_schema` tool: fields, kinds, required flags, matrix block types, native fields, writable meta attributes, optional golden-fixture `example`
- `describe_entry_schema` now returns a per-field `input` shape describing the payload each field accepts: relations show their natural-key shape (e.g. `{section, slug}`), Matrix and Hyper-style fields recurse into their block/link types and sub-fields, core Link fields list their configured types and key shapes, option fields list allowed values, tables list their columns, scalars show their value type. Derived dynamically from field layouts and the module's own key contract, so it stays correct for any field including third-party ones.
- `publish_entry`, `delete_entry`, `duplicate_entry`, `copy_entry_to_site` workflow tools
- `entryWriteMode` setting (`'draft'` default, `'live'` restores immediate saves); per-call `mode` override
- `site` parameter on all entry tools ([#9](https://github.com/stimmtdigital/craft-mcp/issues/9)); `search` on `list_entries`, `parent` on the entry write tools
- `search`/`type` filters on `list_fields`, `search` on `list_sections`
- `EVENT_REGISTER_FIELD_TRANSLATORS` for plugins whose field types embed element ids
- HTTP transport (opt-in): serve the MCP server from a Craft endpoint with per-user scoped bearer tokens (`readonly`/`content`/`full`), managed via `craft mcp/tokens/*` console commands. Content editors can connect Claude Desktop to a remote install with no local tooling. (#13)
- Dangerous tools now carry a `destructiveHint` annotation in `tools/list`, making risk visible to MCP clients.

### Changed
- `get_entry`/`list_entries` return the payload format: relations as natural keys ({section,slug}, {volume,filename}, ...), matrix blocks in core shape with translated internals, disabled blocks included; what you read is what create/update accept
- `create_entry`/`update_entry` save as drafts by default (set `entryWriteMode: 'live'` or pass `mode: 'live'` for the old behavior); validation failures return structured per-field errors instead of a JSON blob

## [1.3.0] - 2026-07-07

### Added
- `logLevel` configuration option to control MCP server log verbosity (`storage/logs/mcp-server.log`). Set to `'debug'` for full tool dispatch logging. Default: `'error'` ([#10](https://github.com/stimmtdigital/craft-mcp/issues/10))
- Debug logging in `tinker` tool for execution tracing, security blocks, and error diagnostics

### Fixed
- `tinker` tool now wraps execution in `SafeExecution`, ensuring unexpected errors surface with real messages instead of the generic "Tool execution failed" ([#10](https://github.com/stimmtdigital/craft-mcp/issues/10))
- Stray output (echo/print/PHP notices from tinker'd code or Craft) no longer corrupts the stdio JSON-RPC stream; it is rerouted to stderr via a non-removable output buffer in `bin/mcp-server`
- `tinker` tool blocks unbounded `while (ob_get_level())` teardown loops, which would spin forever against the non-removable stdout shield
- `tinker` tool now drains only output buffers it opened (baseline-aware): the previous `ob_get_level() > 0` guard closed outer buffers such as the stdout shield, clobbering the original error with an `ErrorException` when user code had closed the capture buffer
- Added explicit `psy/psysh` requirement: tinker relied on it arriving transitively (e.g. via `yiisoft/yii2-shell`) and fatally errored on installs without it
- `run_query` and `explain_query` no longer reject legitimate SELECTs whose columns contain a blocked keyword as a substring (e.g. `dateCreated` matched `CREATE`, `dateUpdated` matched `UPDATE`); the read-only guard now matches keywords on word boundaries via the shared `SqlReadGuard`, and `explain_query` gained the stronger keyword set
- `get_deprecations` database lookup selected a nonexistent `template` column, so the query always failed and was silently swallowed; the column is removed and the query now runs
- `get_table_counts` and `get_deprecations` check table existence via schema instead of catching every `Throwable`, so a genuine database error surfaces instead of being masked as "Table not found" / zero results
- `list_asset_folders` no longer dereferences a null root folder when a volume has no indexed root folder
- `list_backups` and `create_backup` guard against `filesize()`/`filemtime()` returning `false` (file removed mid-listing), preventing a `TypeError`
- Added explicit `symfony/polyfill-php84` requirement: `array_any()` (PHP 8.4) is used on the supported `^8.3` floor and previously relied on the polyfill arriving transitively
- `list_event_handlers` reported class-level handlers with the `class` and `event` fields swapped; Yii stores them as `$_events[eventName][className]`, so the two nesting levels were mislabeled

### Changed
- Extracted read-only SQL validation into a shared `SqlReadGuard` support class used by `run_query` and `explain_query`
- Upgraded `mcp/sdk` from `^0.4` to `^0.6`
- Added explicit `symfony/finder` requirement: the SDK moved it from `require` to `suggest` in 0.5, but file-based discovery (used for tool/prompt/resource registration) still needs it at server boot

## [1.2.2] - 2026-03-04

### Fixed
- `tinker` tool now releases mutex locks after execution, preventing project config deadlocks in long-running MCP process ([#7](https://github.com/stimmtdigital/craft-mcp/issues/7))

### Added
- `MutexGuard` support class for releasing Yii2 mutex locks and resetting Craft's internal lock state

## [1.2.1] - 2026-02-24

### Changed
- Upgraded `mcp/sdk` from `^0.3` to `^0.4`

## [1.2.0] - 2026-02-24

### Added
- Monorepo-aware project root detection in `mcp/install` wizard
  - Auto-detects `.ddev`/`.git` markers in parent directories
  - Config files (`.mcp.json`, `.cursor/mcp.json`) placed at actual project root
  - Binary paths automatically prefixed with Craft subdirectory (e.g., `backend/vendor/...`)
- `ProjectRootResolver` class for clean project root resolution (SRP)
- Unit tests for `ProjectRootResolver`

### Changed
- Upgraded `mcp/sdk` from `^0.2` to `^0.3`
- PHPStan ignore patterns updated for Craft CMS generic type annotations

### Fixed
- `mcp/install` now writes config files to the correct location in monorepo setups
- Removed dead code branch in `McpServerFactory`

## [1.1.1] - 2026-01-07

### Added
- Interactive `mcp/install` wizard for generating MCP client configuration files
  - Auto-detects DDEV vs native PHP environment
  - Supports Claude Code, Cursor, and Claude Desktop clients
  - Options: `--environment` (`-e`), `--serverName` (`-s`)
- `read_logs` tool now supports `pattern` parameter for case-insensitive content search
- `read_logs` tool now supports `source` parameter to target specific log files (web, console, queue, or plugin name)
- `read_logs` tool now discovers plugin logs recursively in subdirectories
- `read_logs` tool now parses multi-line stack traces into structured arrays
- `read_logs` tool now sends progress notifications to clients while parsing multiple files
- `read_logs` tool now supports `output` parameter (`structured` or `text`) for human-readable colored output
- New `ResponseFormat` enum for reusable tool output format selection
- New `LogParser`, `LogEntry`, `StackFrame`, and `LogFormatter` classes for clean log architecture

### Changed
- Refactored log parsing into dedicated `LogParser` class for cleaner architecture
- `FileHelper::tail()` now uses chunk-based reading for improved performance on large files

### Fixed
- Fixed null pointer errors in entry, category, and product serialization when related model (section/type/group) is missing
- `list_console_commands` now discovers all commands including those from plugins
- `tinker` tool now properly preserves indentation in multi-line PHP input

## [1.1.0] - 2026-01-04

### Added
- 18 new MCP tools expanding the toolset from 32 to 50 tools:
  - Multi-site tools: `list_sites`, `get_site`, `list_site_groups`
  - GraphQL tools: `list_graphql_schemas`, `get_graphql_schema`, `execute_graphql`, `list_graphql_tokens`
  - Backup tools: `list_backups`, `create_backup`
  - Self-awareness tools: `get_mcp_info`, `list_mcp_tools`, `reload_mcp`
  - Commerce tools (Craft Commerce): `list_products`, `get_product`, `list_orders`, `get_order`, `list_order_statuses`, `list_product_types`
- 9 MCP prompts for guided analysis workflows
  - Content analysis: `content_health_analysis`, `content_audit`, `debug_content_issue`
  - Entry management: `create_entry_guide`, `query_entries_guide`, `bulk_entry_operations`
  - Schema exploration: `explore_section_schema`, `field_usage_analysis`, `explore_content_model`
- 12 MCP resources for read-only URI-based data access
  - Schema resources: `craft://schema/sections`, `craft://schema/fields`, `craft://schema/sections/{handle}`, `craft://schema/fields/{handle}`
  - Config resources: `craft://config/general`, `craft://config/routes`, `craft://config/sites`, `craft://config/volumes`, `craft://config/plugins`
  - Content resources: `craft://entries/{section}`, `craft://entries/{section}/{slug}`, `craft://entries/{section}/stats`
- 7 completion providers for intelligent parameter auto-completion
- Extension system for custom prompts via `EVENT_REGISTER_PROMPTS` event
- Extension system for custom resources via `EVENT_REGISTER_RESOURCES` event
- SSH tunnel configuration example for remote server access
- Comprehensive documentation for prompts (`docs/prompts.md`)
- Comprehensive documentation for resources (`docs/resources.md`)
- Extended documentation in `docs/extending.md` covering prompts, resources, and completion providers
- Hot-reload support for newly installed plugins without MCP server restart

### Changed
- Tool count updated from 32 to 50 tools
- Documentation updated to reflect all available features
- Refactored error handling to align with MCP SDK conventions
- Applied Rector code quality improvements
- PSR-12 compliance across all files
- Added unit tests for new tool classes (BackupTools, CommerceTools, GraphqlTools, McpTools, SiteTools)

## [1.0.1] - 2026-01-03

### Fixed
- `clear_caches` tool now uses correct `CraftFileHelper::clearDirectory()` instead of non-existent `Fs::clearDirectory()`
- `read_logs` tool now dynamically finds all log files including date-based logs (e.g., `web-2026-01-03.log`)
- `read_logs` tool regex pattern updated to match Craft CMS log format
- `get_environment` tool removed non-existent `getTranslationsPath()` call

## [1.0.0] - 2026-01-03

### Added
- Initial release
- 32 MCP tools for Craft CMS
- Schema & Structure tools (list_plugins, list_sections, list_fields, list_volumes, list_routes, list_console_commands)
- Content tools (list_entries, get_entry, create_entry, update_entry, list_globals, list_categories, list_users)
- Asset tools (list_assets, get_asset, list_asset_folders)
- System tools (get_system_info, get_config, read_logs, get_last_error, clear_caches)
- Database tools (get_database_info, get_database_schema, get_table_counts, run_query)
- Tinker tool for PHP execution
- Debugging tools (get_queue_jobs, get_project_config_diff, get_deprecations, explain_query, get_environment, list_event_handlers)
- Extension system via `EVENT_REGISTER_TOOLS` event
- Production safety defaults (disabled by default in production)
- Dangerous tools protection (tinker, run_query, create_entry, update_entry, clear_caches)