# Installation

This guide walks you through installing Craft MCP and connecting it to your preferred AI assistant.

## Requirements

Before installing Craft MCP, ensure your environment meets these requirements:

- **Craft CMS** 5.0 or later
- **PHP** 8.3 or later

The plugin is compatible with all database drivers supported by Craft CMS (MySQL, PostgreSQL).

## Install via Composer

### From Packagist

The recommended way to install Craft MCP is via Composer from Packagist:

```bash
composer require stimmt/craft-mcp
```

After Composer finishes downloading the package, install the plugin through Craft's CLI:

```bash
php craft plugin/install mcp
```

### Local Development

If you're contributing to Craft MCP or developing it locally, you can install from a local path:

```bash
# Add the local repository to your composer.json
composer config repositories.craft-mcp path ./plugins/craft-mcp

# Require the package with dev stability
composer require stimmt/craft-mcp:@dev

# Install the plugin in Craft
php craft plugin/install mcp
```

This approach allows you to make changes to the plugin code and see them reflected immediately without re-publishing to Packagist.

## Verify Installation

To confirm the plugin was installed successfully, run:

```bash
php craft plugin/list
```

You should see `mcp` in the list with status `Installed`. If the plugin appears but shows a different status, you may need to enable it through the Craft control panel or by running `php craft plugin/enable mcp`.

## Enable the MCP Server

By default, the MCP server is disabled for security. Create a configuration file at `config/mcp.php` to enable it:

```php
<?php

return [
    'enabled' => true,
];
```

For production environments, you'll want to review the security options in the [Configuration Guide](configuration.md), including IP allowlists and tool restrictions.

## MCP Client Setup

Once the plugin is installed and configured, you'll need to tell your AI assistant how to connect to the MCP server.

### Automatic Setup (Recommended)

The easiest way to configure your MCP clients is using the built-in installation wizard:

```bash
php craft mcp/install
```

The wizard will:
1. Detect your environment (DDEV or native PHP)
2. Let you select which clients to configure (Claude Code, Cursor, Claude Desktop)
3. Generate the appropriate configuration files automatically

**Options:**

| Option | Alias | Description |
|--------|-------|-------------|
| `--environment` | `-e` | Override detected environment (`ddev` or `native`) |
| `--serverName` | `-s` | Custom server name (default: `craft-cms`) |

**Examples:**

```bash
# Interactive wizard with auto-detection
php craft mcp/install

# Force DDEV environment
php craft mcp/install --environment=ddev

# Use a custom server name
php craft mcp/install --serverName=my-craft-site

# Non-interactive mode (use defaults)
php craft mcp/install --interactive=0
```

The wizard handles all the details: creating directories, setting correct paths for your environment, and warning you if a server with the same name already exists.

### Manual Setup

If you prefer to configure manually or need to understand the configuration format, follow the instructions below for your specific client.

#### Claude Code

Claude Code looks for MCP server configurations in a `.mcp.json` file in your project root.

1. Create a new file called `.mcp.json` in your Craft project root
2. Add the server configuration based on your local development environment:

<details>
<summary><strong>With DDEV</strong> (recommended for most Craft projects)</summary>

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
<summary><strong>Without DDEV</strong> (native PHP installation)</summary>

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

Cursor stores MCP configurations in a `.cursor/mcp.json` file within your project.

1. Create the `.cursor` directory in your project root if it doesn't already exist
2. Create a new file called `mcp.json` inside the `.cursor` directory
3. Add the server configuration:

<details>
<summary><strong>With DDEV</strong> (recommended for most Craft projects)</summary>

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
<summary><strong>Without DDEV</strong> (native PHP installation)</summary>

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

Claude Desktop uses a global configuration file rather than a project-specific one. Unlike Claude Code and Cursor, it requires absolute paths for the command and working directory.

1. Open your Claude Desktop configuration file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
2. Add the Craft MCP server to the `mcpServers` object:

<details>
<summary><strong>With DDEV</strong> (recommended for most Craft projects)</summary>

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

Replace `/usr/local/bin/ddev` with the actual path to your DDEV installation (find it by running `which ddev` in your terminal), and update the `cwd` value to point to your Craft project directory.
</details>

<details>
<summary><strong>Without DDEV</strong> (native PHP installation)</summary>

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

Replace `/usr/bin/php` with the actual path to your PHP installation (find it by running `which php` in your terminal), and update the `cwd` value to point to your Craft project directory.
</details>

## Troubleshooting

### Server not starting

If the MCP server fails to start, check the following:

1. **Plugin is enabled**: Run `php craft plugin/list` and verify the MCP plugin shows as `Installed`
2. **Configuration exists**: Ensure `config/mcp.php` exists and contains `'enabled' => true`
3. **PHP path is correct**: For Claude Desktop, verify your absolute paths are correct using `which php` or `which ddev`
4. **DDEV is running**: If using DDEV, ensure your containers are up with `ddev start`

### Tools not appearing

If the MCP server connects but tools aren't showing up:

1. **Check for errors**: Look at your Craft logs in `storage/logs/` for any plugin errors
2. **Verify PHP version**: Ensure you're running PHP 8.3 or later

## Next Steps

Now that Craft MCP is installed and connected, you may want to:

1. **[Configure the plugin](configuration.md)** - Review security settings and customize which tools are available
2. **[Explore available tools](tools/README.md)** - Learn what your AI assistant can now do with your Craft installation
