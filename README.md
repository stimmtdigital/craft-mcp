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

At its foundation, Craft MCP is an MCP server equipped with 50 specialized tools, 9 analysis prompts, and 12 data resources designed to streamline AI-assisted workflows in Craft projects. Rather than manually describing your field layouts, Matrix configurations, or entry structures, your AI assistant can query this information directly from your installation, ensuring accurate and context-aware code generation.

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

### Quick Setup (Recommended)

Run the interactive configuration wizard to automatically generate config files for your MCP clients:

```bash
php craft mcp/install
```

The wizard will:
- Detect your environment (DDEV or native PHP)
- Let you select which clients to configure (Claude Code, Cursor, Claude Desktop)
- Generate the appropriate configuration files

Options:
- `-e, --environment` - Override detected environment (`ddev` or `native`)
- `-s, --serverName` - Custom server name (default: `craft-cms`)

### Manual Setup

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

#### Remote Server via SSH (Not Recommended)

For development environments on remote servers, you can tunnel through SSH. Note that this approach is not recommended for production use due to security considerations.

<details>
<summary>SSH tunnel configuration</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ssh",
      "args": [
        "-t",
        "user@your-server.com",
        "cd /path/to/craft/project && php vendor/stimmt/craft-mcp/bin/mcp-server"
      ]
    }
  }
}
```

Requirements:
- SSH key authentication must be configured (no password prompts)
- The remote server must have PHP 8.2+ available
- The Craft MCP plugin must be installed on the remote installation

</details>

## Available MCP Tools

### Content Tools
| Name | Notes |
|------|-------|
| List Entries | Query entries with filtering by section, status, author, and limit |
| Get Entry | Retrieve full entry details including all custom field values |
| Create Entry | Create new entries in any section with field data |
| Update Entry | Modify existing entry content and custom fields |
| List Assets | Browse assets with volume and folder filtering |
| Get Asset | Get detailed asset information including dimensions and metadata |
| List Asset Folders | List folder structure within asset volumes |
| List Categories | Query categories by group with hierarchy information |
| List Users | Query users with group filtering |
| List Globals | List all global sets with their field values |

### Schema & Structure Tools
| Name | Notes |
|------|-------|
| List Sections | Inspect all sections with their entry types and field layouts |
| List Fields | Get all fields with types, settings, and group assignments |
| List Volumes | Inspect asset volume configurations and filesystem settings |
| List Plugins | Get installed plugins with version, status, and settings |

### System Tools
| Name | Notes |
|------|-------|
| Get System Info | Read Craft version, PHP version, database driver, and environment |
| Get Config | Read Craft general config and plugin configuration values |
| Read Logs | Search and filter application log entries by level and date |
| Get Last Error | Retrieve the most recent error from logs |
| Clear Caches | Clear specific caches or all caches at once |
| List Routes | Inspect all registered routes including controller actions |
| List Console Commands | List available Craft CLI commands |

### Database Tools
| Name | Notes |
|------|-------|
| Get Database Info | Get database connection details and server version |
| Get Database Schema | Inspect the complete database schema with all tables and columns |
| Get Table Counts | Get row counts for core Craft tables |
| Run Query | Execute read-only SELECT queries against the database |

### Debugging Tools
| Name | Notes |
|------|-------|
| Get Queue Jobs | Inspect queue jobs by status (pending, reserved, failed, done) |
| Get Project Config Diff | Show pending project config changes that need to be applied |
| Get Deprecations | Read deprecation warnings from logs and the deprecations table |
| Explain Query | Run EXPLAIN on queries for performance analysis |
| Get Environment | Read safe environment information (no secrets exposed) |
| List Event Handlers | Inspect registered Yii event handlers and listeners |
| Tinker | Execute arbitrary PHP code within your Craft application context |

### Multi-Site Tools
| Name | Notes |
|------|-------|
| List Sites | Get all sites with handles, languages, and base URLs |
| Get Site | Get detailed site information by ID or handle |
| List Site Groups | List site groups with their associated sites |

### GraphQL Tools
| Name | Notes |
|------|-------|
| List GraphQL Schemas | List all GraphQL schemas with their scopes |
| Get GraphQL Schema | Get schema details including SDL |
| Execute GraphQL | Run GraphQL queries and mutations |
| List GraphQL Tokens | List API tokens with their associated schemas |

### Backup Tools
| Name | Notes |
|------|-------|
| List Backups | List available database backups |
| Create Backup | Create a new database backup |

### Self-Awareness Tools
| Name | Notes |
|------|-------|
| Get MCP Info | Get plugin version, status, and configuration |
| List MCP Tools | List all available tools with descriptions and enabled status |
| Reload MCP | Reload to detect newly installed plugins without server restart |

### Commerce Tools (when Craft Commerce is installed)
| Name | Notes |
|------|-------|
| List Products | List products with variant information |
| Get Product | Get detailed product information |
| List Orders | List orders with status filtering |
| Get Order | Get order details by ID or number |
| List Order Statuses | List available order statuses |
| List Product Types | List product type configurations |

## Extending

Other Craft plugins and modules can register their own MCP tools by listening to the `EVENT_REGISTER_TOOLS` event. This allows you to expose plugin-specific functionality to AI assistants, such as custom element types, module APIs, or specialized queries.

See the [Extending Guide](docs/extending.md) for implementation details, code examples, and best practices for tool development.

## Documentation

- **[Installation](docs/installation.md)** - Requirements, Composer setup, and detailed installation steps
- **[Configuration](docs/configuration.md)** - All configuration options, environment variables, and security settings
- **[Tools Reference](docs/tools/README.md)** - Complete documentation for all 50 tools with parameters and examples
- **[Prompts](docs/prompts.md)** - Pre-built analysis prompts for content health, audits, and schema exploration
- **[Resources](docs/resources.md)** - Read-only URI-based access to schema, config, and content data
- **[Extending](docs/extending.md)** - Guide for plugin and module developers to register custom tools, prompts, and resources

## Contributing

Thank you for considering contributing to Craft MCP! Please see [GitHub Issues](https://github.com/stimmtdigital/craft-mcp/issues) for bug reports, feature requests, and discussion.

## Credits

- Created and maintained by [Max van Essen](https://github.com/vanEssenMax)
- Inspired by [Laravel Boost](https://github.com/laravel/boost)
- Plugin icon from [Lucide](https://lucide.dev) (MIT)

## License

Craft MCP is open-sourced software licensed under the [MIT license](LICENSE).
