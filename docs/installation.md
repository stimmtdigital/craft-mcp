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

### Beta Releases

Pre-releases are tagged like `v1.4.0-beta.1` and marked as pre-release on GitHub. Composer never installs them by accident: its default stability is `stable`, so regular installs keep resolving to the latest stable version. To test a beta, opt in explicitly:

```bash
composer require stimmt/craft-mcp:^1.4.0@beta
```

Most Craft projects ship with `minimum-stability: dev` and `prefer-stable: true` in their root `composer.json`, so an exact constraint like `^1.4.0-beta.1` also resolves without extra flags. When the stable version is released, the same constraint upgrades to it with a normal `composer update`.

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

Once the plugin is installed and enabled, connect your AI assistant. The quickest route is the built-in wizard:

```bash
php craft mcp/install
```

The wizard detects your environment (DDEV or native PHP), lets you pick clients (Claude Code, Cursor, Claude Desktop), and writes the config files for you.

Wizard options, manual configuration for every client, and the SSH variant live in the [Client Setup guide](client-setup.md). Remote users without a local checkout connect through the [HTTP transport](http-transport.md) instead.

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
