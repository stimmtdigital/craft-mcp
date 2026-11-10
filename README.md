<p align="center">
  <img src="src/icon.svg" alt="Craft MCP" width="100" height="100">
</p>

<p align="center">
  <a href="https://github.com/stimmtdigital/craft-mcp/actions/workflows/ci.yml"><img src="https://github.com/stimmtdigital/craft-mcp/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/stimmt/craft-mcp"><img src="https://img.shields.io/packagist/dt/stimmt/craft-mcp" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/stimmt/craft-mcp"><img src="https://img.shields.io/packagist/v/stimmt/craft-mcp" alt="Latest Stable Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/stimmt/craft-mcp" alt="License"></a>
</p>

## Introduction

Craft CMS 5.12 ships its own MCP (Model Context Protocol) server: an HTTP endpoint with OAuth login and scopes, and a stdio server for local tools. Craft MCP 2.0 is no longer a server of its own. It adds tools to Craft's:

- **Developer tools**: database schema and read-only SQL, logs, config, routes, event handlers, deprecations, GraphQL, backups, and a tinker tool for running PHP
- **Content extras**: grouped entry counts, natural keys (`{"section": "news", "slug": "launch"}`) turned into the ids Craft's tools take, and preview links for review
- **Categories, global sets and Craft Commerce**, which Craft's own tools do not cover
- **Pro**: publishing that validates a draft as live content first, copying entries between sites, and adding or moving a single Matrix block

Every plugin tool is named `stimmt_…`. Clients connect to Craft's endpoint, log in with their Craft account, and see the plugin's tools next to Craft's.

Upgrading from 1.x? 2.0 changes the config file, the endpoint and every tool name: follow [UPGRADE.md](UPGRADE.md).

## Requirements

- Craft CMS 5.12.1 or later
- PHP 8.3 or later

## Quick Start

1. Install the plugin:

   ```bash
   composer require stimmt/craft-mcp
   php craft plugin/install mcp
   ```

2. In the control panel, go to **Settings → MCP** and enable the authenticated server. **Use endpoint paths that do not start with `mcp` inside the control panel**, for example `/admin/ai` and `/admin/ai/auth` (or `/ai` and `/ai/auth` when the control panel has no `cpTrigger`). The plugin's handle is `mcp`, so Craft would send a control panel path starting with `mcp` to the plugin, and clients would get a login page.

3. Check the setup:

   ```bash
   php craft mcp/check
   ```

   It reports Craft's MCP server, the endpoint paths, the OAuth keys, leftover 1.x settings in `config/mcp.php`, the plugin's tools and the edition, and exits non-zero on an error.

See [Installation](docs/installation.md) for details.

## Connecting clients

- **Claude (web, Desktop, mobile), ChatGPT and other HTTP clients**: add a custom connector with Craft's endpoint URL, log in to Craft, and choose read only, read and write, or (admins) full access.
- **Claude Code**: `claude mcp add --transport http craft <url>`, or run Craft's stdio server locally.
- **Local tools, scripts and CI**: `php craft mcp/serve --user=<id|username|email>` runs Craft's stdio server as that user. `php craft mcp/install` writes the config for Claude Code, Cursor, Claude Desktop and Codex.

See [Connecting clients](docs/connecting.md). For editors: [Using Claude with your Craft site](docs/claude-for-editors.md).

## The Toolbox

| Area | Tools | Gate | Edition |
|------|-------|------|---------|
| [Content](docs/tools/content.md) | `stimmt_entries_count`, `stimmt_refs_resolve`, `stimmt_entries_preview_url`, `stimmt_categories_list` | read | Lite |
| | `stimmt_globals_list` | admin | Lite |
| | `stimmt_drafts_publish`, `stimmt_entries_copy_to_site`, `stimmt_nested_entries_create`, `stimmt_nested_entries_move` | write | Pro |
| [Database](docs/tools/database.md) | `stimmt_db_schema`, `stimmt_db_info`, `stimmt_db_table_counts`, `stimmt_db_query`, `stimmt_db_explain` | admin | Lite |
| [System](docs/tools/system.md) | `stimmt_config_get`, `stimmt_logs_read`, `stimmt_errors_last`, `stimmt_console_commands_list`, `stimmt_routes_list` | admin | Lite |
| [Debugging](docs/tools/debugging.md) | `stimmt_deprecations_list`, `stimmt_environment_get`, `stimmt_event_handlers_list`, `stimmt_tinker` | admin | Lite |
| [GraphQL](docs/tools/graphql.md) | `stimmt_graphql_schemas_list`, `stimmt_graphql_schema_get`, `stimmt_graphql_query`, `stimmt_graphql_execute`, `stimmt_graphql_tokens_list` | admin | Lite |
| [Backup](docs/tools/backup.md) | `stimmt_backups_list`, `stimmt_backups_create` | admin | Lite |
| [Commerce](docs/tools/commerce.md) | `stimmt_commerce_products_list`, `stimmt_commerce_product_get`, `stimmt_commerce_orders_list`, `stimmt_commerce_order_get` | admin | Lite |
| | `stimmt_commerce_order_statuses_list`, `stimmt_commerce_product_types_list` | read | Lite |

**read**, **write** and **admin** are the scopes a user approves when connecting (`mcp:read`, `mcp:write`, `mcp:admin`). Admin tools are for Craft admin users only. Commerce tools exist only while Craft Commerce is installed.

`stimmt_tinker`, `stimmt_db_query`, `stimmt_graphql_execute` and `stimmt_backups_create` are **dangerous**: they are off in production unless `enableDangerousTools` is set for that environment in `config/craft-mcp.php`. See [Configuration](docs/configuration.md) and the [Tools Reference](docs/tools/README.md).

## Editions

**Lite** is free and includes every tool above except four. **Pro** adds `stimmt_drafts_publish`, `stimmt_entries_copy_to_site`, `stimmt_nested_entries_create` and `stimmt_nested_entries_move`. Upgrade in the control panel under **Settings → Plugins**. See [Editions](docs/editions.md).

## Documentation

The [documentation index](docs/README.md) links everything:

- **[Installation](docs/installation.md)**: setup and troubleshooting
- **[Connecting clients](docs/connecting.md)**: HTTP, scopes, stdio and the install wizard
- **[Configuration](docs/configuration.md)**: `config/craft-mcp.php`
- **[Editions](docs/editions.md)**: Lite and Pro
- **[Tools Reference](docs/tools/README.md)**: every tool
- **[Agent guide](docs/agent-guide.md)**: how the tools fit together, written for the AI agent
- **[Extending](docs/extending.md)**: your own tools, and field translators
- **[Upgrading from 1.x](UPGRADE.md)**

## Contributing

Thank you for considering contributing to Craft MCP! Please see [GitHub Issues](https://github.com/stimmtdigital/craft-mcp/issues) for bug reports, feature requests, and discussion.

## Credits

- Created by [Max van Essen](https://github.com/vanEssenMax)
- Inspired by [Laravel Boost](https://github.com/laravel/boost)
- Plugin icon from [Lucide](https://lucide.dev) (MIT)

## License

Craft MCP is open-sourced software licensed under the [MIT license](LICENSE).
