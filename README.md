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

Craft MCP accelerates AI-assisted development by giving your AI assistant direct access to your Craft installation's content architecture, database schema, and configuration.

At its foundation, Craft MCP is an MCP server equipped with 32 specialized tools designed to streamline AI-assisted workflows in Craft projects. Rather than manually describing your field layouts, Matrix configurations, or entry structures, your AI assistant can query this information directly from your installation, ensuring accurate and context-aware code generation.

The tools span content management (entries, assets, categories, users), schema inspection (sections, fields, volumes, entry types), system administration (configuration, logs, caches, plugins), database operations (schema inspection, query execution), and debugging utilities (queue jobs, deprecations, project config). For advanced use cases, a Tinker tool allows executing PHP code directly within your Craft application context.

## Installation

Craft MCP can be installed via Composer:

```bash
composer require stimmt/craft-mcp
```

Next, install the plugin through Craft's CLI:

```bash
php craft plugin/install mcp
```

Then create a configuration file at `config/mcp.php` to enable the MCP server. By default, the server is disabled in production environments for security:

```php
<?php

return [
    'enabled' => true,
];
```

For additional configuration options including disabling specific tools, IP allowlists, and environment-specific settings, see the [Configuration Guide](docs/configuration.md).

Once Craft MCP has been installed, you're ready to connect Claude Code, Cursor, Claude Desktop, or your AI assistant of choice.

### Set up Your Code Editors

#### Claude Code

1. Create a new file called `.mcp.json` in your Craft project root
2. Add the server configuration based on your local environment:

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "php",
      "args": ["vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

#### Cursor

1. Create a new file called `.cursor/mcp.json` in your Craft project root (create the `.cursor` directory if it doesn't exist)
2. Add the server configuration based on your local environment:

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "php",
      "args": ["vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

#### Claude Desktop

1. Open your Claude Desktop configuration file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
2. Add the server configuration. Unlike Claude Code and Cursor, Claude Desktop requires absolute paths for both the command and working directory:

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "/usr/local/bin/ddev",
      "args": ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"],
      "cwd": "/path/to/your/craft/project"
    }
  }
}
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "/usr/bin/php",
      "args": ["vendor/stimmt/craft-mcp/bin/mcp-server"],
      "cwd": "/path/to/your/craft/project"
    }
  }
}
```
</details>

You can find your absolute paths by running `which ddev` or `which php` in your terminal.

## Available MCP Tools

| Name | Notes |
|------|-------|
| List Entries | Query entries with filtering by section, status, author, and limit |
| Get Entry | Retrieve full entry details including all custom field values |
| Create Entry | Create new entries in any section with field data |
| Update Entry | Modify existing entry content and custom fields |
| List Assets | Browse assets with volume and folder filtering |
| List Categories | Query categories by group with hierarchy information |
| List Users | Query users with group filtering |
| List Sections | Inspect all sections with their entry types and field layouts |
| List Fields | Get all fields with types, settings, and group assignments |
| List Volumes | Inspect asset volume configurations and filesystem settings |
| List Category Groups | Get category group definitions and field layouts |
| Get Config | Read Craft general config and plugin configuration values |
| Read Logs | Search and filter application log entries by level and date |
| List Routes | Inspect all registered routes including controller actions |
| Clear Caches | Clear specific caches or all caches at once |
| List Plugins | Get installed plugins with version, status, and settings |
| Get Craft Info | Read Craft version, PHP version, database driver, and environment |
| Get Database Schema | Inspect the complete database schema with all tables and columns |
| Get Table Info | Get detailed structure for specific tables including indexes |
| Run Query | Execute read-only SELECT queries against the database |
| List Tables | List all database tables with row counts |
| Get Queue Jobs | Inspect queue jobs by status (pending, reserved, failed, done) |
| Get Project Config Diff | Show pending project config changes that need to be applied |
| Get Deprecations | Read deprecation warnings from logs and the deprecations table |
| Explain Query | Run EXPLAIN on queries for performance analysis |
| Get Environment | Read safe environment information (no secrets exposed) |
| List Event Handlers | Inspect registered Yii event handlers and listeners |
| Tinker | Execute arbitrary PHP code within your Craft application context |

## Extending

Other Craft plugins and modules can register their own MCP tools by listening to the `EVENT_REGISTER_TOOLS` event. This allows you to expose plugin-specific functionality to AI assistants, such as custom element types, module APIs, or specialized queries.

See the [Extending Guide](docs/extending.md) for implementation details, code examples, and best practices for tool development.

## Documentation

- **[Installation](docs/installation.md)** - Requirements, Composer setup, and detailed installation steps
- **[Configuration](docs/configuration.md)** - All configuration options, environment variables, and security settings
- **[Tools Reference](docs/tools/README.md)** - Complete documentation for all 32 tools with parameters and examples
- **[Extending](docs/extending.md)** - Guide for plugin and module developers to register custom tools

## Contributing

Thank you for considering contributing to Craft MCP! Please see [GitHub Issues](https://github.com/stimmtdigital/craft-mcp/issues) for bug reports, feature requests, and discussion.

## Credits

- Created and maintained by [Max van Essen](https://github.com/vanEssenMax)
- Inspired by [Laravel Boost](https://github.com/laravel/boost)
- Plugin icon from [Lucide](https://lucide.dev) (MIT)

## License

Craft MCP is open-sourced software licensed under the [MIT license](LICENSE).
