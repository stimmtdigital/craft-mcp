# Craft MCP Documentation

Craft CMS 5.12 ships its own MCP server. Craft MCP 2.0 adds tools to that server: developer tools, categories and global sets, Craft Commerce, grouped counts, natural keys, preview links, validated publishing and single Matrix-block writes. Clients connect to Craft's endpoint and log in with their Craft account; the `stimmt_` tools appear next to Craft's own.

Upgrading from 1.x: see [UPGRADE.md](../UPGRADE.md).

## Setting up

- **[Installation](installation.md)**: requirements, turning on Craft's MCP server, endpoint paths, OAuth keys, `php craft mcp/check`
- **[Connecting clients](connecting.md)**: Claude, ChatGPT, Claude Code and other clients over HTTP; scopes; stdio for local tools, scripts and CI; the `mcp/install` wizard
- **[Configuration](configuration.md)**: `config/craft-mcp.php`, production defaults, per-environment settings
- **[Editions](editions.md)**: what Lite and Pro include

## Using it

- **[Using Claude with your Craft site](claude-for-editors.md)**: a short guide to send editors
- **[Agent guide](agent-guide.md)**: how the `stimmt_` tools work with Craft's, written for the AI agent and served to it as `stimmt://guides/tools`

## Tools

- **[Tools Reference](tools/README.md)**: every tool with its scope, edition and whether it is dangerous
- [Content](tools/content.md): counts, natural keys, preview links, publishing, copying between sites, Matrix blocks, categories, global sets, and the payload format
- [Database](tools/database.md): schema, table counts, read-only SQL, `EXPLAIN`
- [System](tools/system.md): config, logs, last error, console commands, routes
- [Debugging](tools/debugging.md): deprecations, environment, event handlers, tinker
- [GraphQL](tools/graphql.md): schemas, SDL, queries, tokens
- [Backup](tools/backup.md): list and create database backups
- [Commerce](tools/commerce.md): products, orders, statuses, product types

## For developers

- **[Extending](extending.md)**: adding your own tools to Craft's MCP server, and the plugin's field translator event

## Links

- [GitHub Repository](https://github.com/stimmtdigital/craft-mcp)
- [Report Issues](https://github.com/stimmtdigital/craft-mcp/issues)
- [Stimmt Digital](https://stimmt.digital)
