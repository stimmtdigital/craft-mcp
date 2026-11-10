# Tools Reference

Every tool the plugin adds to Craft's MCP server. All names start with `stimmt_`, so they never share a name with one of Craft's own tools (Craft refuses to start its MCP server when two capabilities have the same name).

Craft's own tools (`elements_*`, `drafts_*`, `configuration_*`, `project_config_*`, `queue_*` and others) are listed by Craft and are not repeated here.

## Who sees which tool

A client sees a tool only when every one of these allows it:

| Gate | Rule |
|------|------|
| **Scope** (HTTP) | The scope the user approved when connecting: **read** (`mcp:read`) sees read-only tools; **write** (`mcp:write`) adds tools that change content; **admin** (`mcp:admin`) adds admin-only tools. Over stdio (`craft mcp/serve`) there is no scope; the user's own rights decide |
| **Admin** | Admin-only tools are offered only when the acting user is a Craft admin. Over HTTP they also need the admin scope, which Craft offers only to admins |
| **Edition** | Pro tools are hidden on the Lite edition. See [Editions](../editions.md) |
| **Dangerous** | Dangerous tools are hidden while `enableDangerousTools` is off, which is the default in production. See [Configuration](../configuration.md) |
| **Settings** | Tools named in `disabledTools` are hidden, and `enabled => false` hides all of them |
| **Commerce** | Commerce tools exist only while Craft Commerce is installed and enabled |

A hidden tool is also not callable.

## All tools

| Tool | What it does | Scope | Edition | Dangerous |
|------|--------------|-------|---------|-----------|
| **[Content](content.md)** | | | | |
| `stimmt_entries_count` | Count entries, optionally grouped by attribute, date bucket or field value | read | Lite | |
| `stimmt_refs_resolve` | Turn a natural key into the element id Craft's `elements_*` tools take | read | Lite | |
| `stimmt_entries_preview_url` | Preview link for an entry or draft, viewable without login | read | Lite | |
| `stimmt_categories_list` | List categories, by group | read | Lite | |
| `stimmt_globals_list` | List global sets with their field values | admin | Lite | |
| `stimmt_drafts_publish` | Publish a draft after validating it as live content, or enable a disabled entry | write | Pro | |
| `stimmt_entries_copy_to_site` | Copy per-site field values to another site, as a draft | write | Pro | |
| `stimmt_nested_entries_create` | Add one Matrix block without resending its siblings | write | Pro | |
| `stimmt_nested_entries_move` | Move one Matrix block to a new position | write | Pro | |
| **[Database](database.md)** | | | | |
| `stimmt_db_schema` | List tables, or one table's columns and indexes | admin | Lite | |
| `stimmt_db_info` | Database driver, server version and connection details | admin | Lite | |
| `stimmt_db_table_counts` | Row counts for Craft's main tables | admin | Lite | |
| `stimmt_db_query` | Run a read-only SQL `SELECT` | admin | Lite | Yes |
| `stimmt_db_explain` | `EXPLAIN` a `SELECT` | admin | Lite | |
| **[System](system.md)** | | | | |
| `stimmt_config_get` | Read a config value, with credentials redacted | admin | Lite | |
| `stimmt_logs_read` | Read recent log entries, filtered by source, level and text | admin | Lite | |
| `stimmt_errors_last` | The most recent error in the logs | admin | Lite | |
| `stimmt_console_commands_list` | List console commands (stdio only) | admin | Lite | |
| `stimmt_routes_list` | List registered routes | admin | Lite | |
| **[Debugging](debugging.md)** | | | | |
| `stimmt_deprecations_list` | Deprecation warnings from the database and the logs | admin | Lite | |
| `stimmt_environment_get` | Environment, PHP settings and system status, without secrets | admin | Lite | |
| `stimmt_event_handlers_list` | Registered event handlers | admin | Lite | |
| `stimmt_tinker` | Run PHP in Craft's context | admin | Lite | Yes |
| **[GraphQL](graphql.md)** | | | | |
| `stimmt_graphql_schemas_list` | List GraphQL schemas and their scopes | admin | Lite | |
| `stimmt_graphql_schema_get` | A schema's type index, one type's SDL, or one field's SDL | admin | Lite | |
| `stimmt_graphql_query` | Run a read-only GraphQL query | admin | Lite | |
| `stimmt_graphql_execute` | Run GraphQL, mutations included | admin | Lite | Yes |
| `stimmt_graphql_tokens_list` | List GraphQL tokens and their schemas | admin | Lite | |
| **[Backup](backup.md)** | | | | |
| `stimmt_backups_list` | List database backups | admin | Lite | |
| `stimmt_backups_create` | Create a database backup | admin | Lite | Yes |
| **[Commerce](commerce.md)** | | | | |
| `stimmt_commerce_products_list` | List products, by product type | admin | Lite | |
| `stimmt_commerce_product_get` | One product with its variants | admin | Lite | |
| `stimmt_commerce_orders_list` | List completed orders, by status | admin | Lite | |
| `stimmt_commerce_order_get` | One order by id or number | admin | Lite | |
| `stimmt_commerce_order_statuses_list` | List order statuses | read | Lite | |
| `stimmt_commerce_product_types_list` | List product types | read | Lite | |

"admin" means admin users only, with the `mcp:admin` scope over HTTP. "write" needs `mcp:write` or higher, "read" needs `mcp:read` or higher.

## Resource

| URI | Name | Content |
|-----|------|---------|
| `stimmt://guides/tools` | `stimmt_tools_guide` | The [agent guide](../agent-guide.md): how the `stimmt_` tools work together with Craft's |

## Response shape

Tools return JSON. List tools that take a `limit` return this envelope next to their rows:

| Key | Meaning |
|-----|---------|
| `count` | Rows in this response |
| `total` | Rows matching the request, or `null` where the tool cannot count them without redoing the read |
| `limit` | The row cap applied, or `null` where none was |
| `offset` | Where this page started. Only on tools that take an `offset` |
| `hasMore` | Whether rows are left. `null` when the tool cannot tell |

A refused call (bad argument, unknown handle, missing permission) fails as an MCP tool error whose text is the reason. Content writes that fail validation return `success: false` with per-field `errors` instead.
