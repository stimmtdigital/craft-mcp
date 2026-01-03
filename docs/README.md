# Craft CMS MCP Documentation

Welcome to the Craft MCP documentation. This guide covers everything you need to know about installing, configuring, and extending the MCP server for your Craft CMS projects.

## What is Craft MCP?

Craft MCP is an MCP (Model Context Protocol) server that gives AI assistants direct access to your Craft CMS installation. Rather than manually describing your content architecture, field layouts, or database schema, your AI assistant can query this information directly, enabling more accurate and context-aware code generation.

The plugin provides 32 specialized tools spanning content management, schema inspection, system administration, database operations, and debugging utilities.

## Getting Started

If you're new to Craft MCP, we recommend following these steps:

1. **[Installation](installation.md)** - Install the plugin via Composer and configure your code editor
2. **[Configuration](configuration.md)** - Review the available options and security settings
3. **[Tools Reference](tools/README.md)** - Explore the available tools and their capabilities

## Documentation

### Setup & Configuration

- **[Installation](installation.md)** - Requirements, Composer setup, and code editor configuration
- **[Configuration](configuration.md)** - All configuration options, environment variables, and security settings

### Tools Reference

- **[Tools Overview](tools/README.md)** - Quick reference for all 32 available tools
- **[Content Tools](tools/content.md)** - Query and manage entries, assets, categories, and users
- **[System Tools](tools/system.md)** - Access configuration, logs, caches, routes, and plugins
- **[Database Tools](tools/database.md)** - Inspect schema, run queries, and explore table structures
- **[Debugging Tools](tools/debugging.md)** - Monitor queue jobs, project config, deprecations, and more

### For Developers

- **[Extending](extending.md)** - Register custom MCP tools from your plugins or modules

## Quick Reference

Install via Composer:

```bash
composer require stimmt/craft-mcp
php craft plugin/install mcp
```

Enable in `config/mcp.php`:

```php
<?php

return [
    'enabled' => true,
];
```

Connect your AI assistant by adding the server to your editor's MCP configuration. See the [Installation Guide](installation.md#mcp-client-setup) for detailed setup instructions for Claude Code, Cursor, and Claude Desktop.

## Links

- [GitHub Repository](https://github.com/stimmtdigital/craft-mcp)
- [Report Issues](https://github.com/stimmtdigital/craft-mcp/issues)
- [Stimmt Digital](https://stimmt.digital)
