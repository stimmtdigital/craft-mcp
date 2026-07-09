# Craft CMS MCP Documentation

Welcome to the Craft MCP documentation. This guide covers everything you need to know about installing, configuring, using, and extending the MCP server for your Craft CMS projects.

## What is Craft MCP?

Craft MCP is an MCP (Model Context Protocol) server that gives AI assistants direct access to your Craft CMS installation. Rather than manually describing your content architecture, field layouts, or database schema, your AI assistant can query this information directly, write reviewable draft content in a natural-key payload format, and inspect the system it is working in.

The plugin provides more than 50 specialized tools, 9 analysis prompts, and 12 data resources, served over stdio for local development and over HTTP with per-user scoped bearer tokens for remote users.

## Getting Started

If you're new to Craft MCP, we recommend following these steps:

1. **[Installation](installation.md)** - Install the plugin via Composer and enable the server
2. **[Client Setup](client-setup.md)** - Connect Claude Code, Cursor, or Claude Desktop (wizard or manual)
3. **[Configuration](configuration.md)** - Review the available options and security settings
4. **[Tools Reference](tools/README.md)** - Explore the available tools and their capabilities

## Documentation

### Setup & Configuration

- **[Installation](installation.md)** - Requirements, Composer setup, and installation steps
- **[Client Setup](client-setup.md)** - Wizard and manual MCP client configuration (Claude Code, Cursor, Claude Desktop, SSH)
- **[Configuration](configuration.md)** - All configuration options, environment variables, and security settings
- **[HTTP Transport](http-transport.md)** - Remote access over HTTP with per-user scoped bearer tokens (`readonly`/`content`/`full`), token management, and troubleshooting

### Working with Content

- **[Content Writing](content-writing.md)** - The natural-key payload format, draft-first workflow, schema discovery with per-field input shapes, and structured feedback

### Tools Reference

- **[Tools Overview](tools/README.md)** - Quick reference for every available tool
- **[Content Tools](tools/content.md)** - Read, write, and publish entries; assets, categories, users, globals; entry schema discovery
- **[System Tools](tools/system.md)** - Access configuration, logs, caches, routes, and plugins
- **[Database Tools](tools/database.md)** - Inspect schema, run queries, and explore table structures
- **[Debugging Tools](tools/debugging.md)** - Monitor queue jobs, project config, deprecations, and more
- **[Multi-Site Tools](tools/multisite.md)** - Sites, site groups, and per-site details
- **[GraphQL Tools](tools/graphql.md)** - Schemas, SDL, query execution, and tokens
- **[Backup Tools](tools/backup.md)** - Create and list database backups
- **[Self-Awareness Tools](tools/mcp.md)** - Plugin info, tool listing, hot reload
- **[Commerce Tools](tools/commerce.md)** - Products, orders, and statuses (when Craft Commerce is installed)

### Prompts & Resources

- **[Prompts](prompts.md)** - Pre-built analysis prompts for content health, audits, and schema exploration
- **[Resources](resources.md)** - Read-only URI-based access to schema, config, and content data

### For Developers

- **[Extending](extending.md)** - Register custom MCP tools, prompts, resources, and field translators from your plugins or modules

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

Connect your AI assistant with the wizard (`php craft mcp/install`) or manually per the [Client Setup guide](client-setup.md). For remote users without a local checkout, enable the [HTTP transport](http-transport.md) and mint them a token.

## Links

- [GitHub Repository](https://github.com/stimmtdigital/craft-mcp)
- [Report Issues](https://github.com/stimmtdigital/craft-mcp/issues)
- [Stimmt Digital](https://stimmt.digital)
