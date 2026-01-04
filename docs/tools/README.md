# Tools Reference

Craft MCP provides 50 tools that give AI assistants comprehensive access to your Craft installation. This page provides a quick reference to all available tools, organized by category.

## Tool Categories

The tools are organized into logical categories based on what they do:

| Category | Tools | Description |
|----------|-------|-------------|
| [Content](content.md) | 10 | Query and manage entries, assets, categories, users, and global sets |
| [System](system.md) | 7 | Access configuration, logs, caches, routes, and system information |
| [Database](database.md) | 4 | Inspect database schema, run queries, and explore table structures |
| [Debugging](debugging.md) | 7 | Monitor queue jobs, project config changes, deprecations, and more |
| Schema | 4 | Inspect sections, fields, volumes, and plugins |
| [Multi-Site](multisite.md) | 3 | Manage and inspect multi-site configurations |
| [GraphQL](graphql.md) | 4 | Query schemas, tokens, and execute GraphQL operations |
| [Backup](backup.md) | 2 | List and create database backups |
| [Self-Awareness](mcp.md) | 3 | Inspect MCP plugin status, available tools, and hot-reload |
| [Commerce](commerce.md) | 6 | Product and order management (requires Craft Commerce) |

Click on a category name to see detailed documentation for each tool, including parameters, response formats, and usage examples.

## Quick Reference

### Content Tools

These tools let AI assistants work with your Craft content:

| Tool | Description |
|------|-------------|
| `list_entries` | Query entries with filtering by section, status, author, site, and more |
| `get_entry` | Retrieve a single entry by ID or slug, including all custom field values |
| `create_entry` | Create new entries in any section with title, slug, and field data |
| `update_entry` | Modify existing entry content, status, or custom field values |
| `list_assets` | Browse assets with filtering by volume, folder, filename, and kind |
| `get_asset` | Get detailed asset information including dimensions, file size, and metadata |
| `list_asset_folders` | List folder structure within a specific asset volume |
| `list_categories` | Query categories by group with hierarchy and custom field values |
| `list_users` | List Craft users with filtering by group, status, and email |
| `list_globals` | List all global sets with their current field values |

### Schema Tools

These tools help AI assistants understand your content architecture:

| Tool | Description |
|------|-------------|
| `list_sections` | Get all sections with their entry types, handles, and field layouts |
| `list_fields` | List all custom fields with types, settings, and group assignments |
| `list_volumes` | Inspect asset volume configurations and filesystem settings |
| `list_plugins` | Get installed plugins with versions, handles, and enabled status |

### System Tools

These tools provide access to Craft's configuration and system status:

| Tool | Description |
|------|-------------|
| `get_system_info` | Get Craft version, PHP version, database driver, and environment info |
| `get_config` | Read values from general config, plugin settings, or custom config files |
| `read_logs` | Search and filter application log entries by level, date, and content |
| `get_last_error` | Retrieve the most recent error from the application logs |
| `clear_caches` | Clear specific Craft caches or all caches at once |
| `list_console_commands` | List all available Craft CLI commands with descriptions |
| `list_routes` | Inspect all registered routes including controller actions and patterns |

### Database Tools

These tools let AI assistants explore and query your database:

| Tool | Description |
|------|-------------|
| `get_database_info` | Get database connection details, driver, and server version |
| `get_database_schema` | List all tables or get column details for a specific table |
| `get_table_counts` | Get row counts for core Craft tables (entries, elements, users, etc.) |
| `run_query` | Execute read-only SELECT queries and return the results |

### Debugging Tools

These tools help with troubleshooting and performance analysis:

| Tool | Description |
|------|-------------|
| `get_queue_jobs` | List queue jobs by status (pending, reserved, failed, done) |
| `get_project_config_diff` | Show pending project config changes waiting to be applied |
| `get_deprecations` | Get deprecation warnings from logs and the deprecations database table |
| `explain_query` | Run EXPLAIN on a SELECT query for performance analysis |
| `get_environment` | Read environment information without exposing sensitive values |
| `list_event_handlers` | Inspect registered Yii event handlers and listeners |
| `tinker` | Execute arbitrary PHP code within your Craft application context |

### Multi-Site Tools

These tools help manage multi-site Craft installations:

| Tool | Description |
|------|-------------|
| `list_sites` | Get all sites with handles, languages, base URLs, and configuration |
| `get_site` | Get detailed information about a specific site by ID or handle |
| `list_site_groups` | List site groups with their associated sites |

### GraphQL Tools

These tools provide access to Craft's GraphQL API:

| Tool | Description |
|------|-------------|
| `list_graphql_schemas` | List all GraphQL schemas with their scopes and permissions |
| `get_graphql_schema` | Get schema details including the SDL (Schema Definition Language) |
| `execute_graphql` | Execute GraphQL queries and mutations against your Craft installation |
| `list_graphql_tokens` | List API tokens with their associated schemas |

### Backup Tools

These tools manage database backups:

| Tool | Description |
|------|-------------|
| `list_backups` | List available database backups from storage/backups |
| `create_backup` | Create a new database backup |

### Self-Awareness Tools

These tools let AI assistants understand the MCP plugin itself:

| Tool | Description |
|------|-------------|
| `get_mcp_info` | Get plugin version, status, tool count, and configuration |
| `list_mcp_tools` | List all available tools with descriptions and enabled status |
| `reload_mcp` | Reload MCP to detect newly installed plugins without server restart |

### Commerce Tools

These tools are only available when Craft Commerce is installed:

| Tool | Description |
|------|-------------|
| `list_products` | List products with filtering by product type |
| `get_product` | Get detailed product information including variants |
| `list_orders` | List orders with filtering by status |
| `get_order` | Get order details by ID or order number |
| `list_order_statuses` | List all configured order statuses |
| `list_product_types` | List product type configurations |

## Dangerous Tools

Some tools can modify data or execute code, which may not be appropriate in all environments. These "dangerous" tools can be disabled through configuration:

| Tool | Risk | What It Does |
|------|------|--------------|
| `tinker` | High | Executes arbitrary PHP code in your application context |
| `execute_graphql` | High | Executes GraphQL queries/mutations that can modify data |
| `run_query` | Medium | Executes SQL queries (limited to SELECT, but still exposes data) |
| `create_entry` | Medium | Creates new entries in your content |
| `update_entry` | Medium | Modifies existing entry content and fields |
| `create_backup` | Medium | Creates files on the server filesystem |
| `clear_caches` | Low | Clears caches, which can temporarily impact site performance |

By default, these tools are disabled when `CRAFT_ENVIRONMENT` is set to `production`. You can control them individually using the `enableDangerousTools` and `disabledTools` configuration options. See the [Configuration Guide](../configuration.md) for details.

## Response Patterns

All tools return consistent response structures that AI assistants can easily parse:

- **List operations** return `count` and an array of items
- **Single record lookups** return `found: true/false` with the record or an error message
- **Operations** return `success: true/false` with data or an error description

See individual tool documentation for specific response formats and examples.
