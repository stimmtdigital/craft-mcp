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

Craft MCP is an MCP (Model Context Protocol) server for Craft CMS. It gives AI assistants direct access to your installation's content, schema, and configuration: rather than describing your field layouts or Matrix setups by hand, the assistant queries them, writes reviewable draft content in a natural-key format, and inspects the system it is working in.

It ships more than 50 specialized tools, 9 analysis prompts, and 12 data resources, served over stdio for local development and over HTTP with scoped bearer tokens for remote users.

## Quick Start

```bash
composer require stimmt/craft-mcp
php craft plugin/install mcp
```

Enable the server in `config/mcp.php` (it is disabled in production by default):

```php
<?php

return [
    'enabled' => true,
];
```

Then run the interactive wizard to configure your MCP clients:

```bash
php craft mcp/install
```

That is the whole happy path. For manual client configuration (Claude Code, Cursor, Claude Desktop, SSH), see the [Client Setup guide](docs/client-setup.md); for all options including tool disabling, IP allowlists, and environment defaults, see the [Configuration guide](docs/configuration.md).

## Remote Access over HTTP

Content editors and office users can point Claude Desktop straight at a remote install: the plugin serves the MCP protocol from a Craft endpoint, authenticated with per-user bearer tokens scoped to `readonly`, `content`, or `full` access. Off by default; enabling it, minting tokens (`php craft mcp/tokens/create`), and troubleshooting are covered in the [HTTP Transport guide](docs/http-transport.md).

## Content Writing for Agents

Entry reads and writes share one payload format: relations as natural keys (`{"section": "pages", "slug": "about"}`), Matrix blocks by type handle, and per-field `input` shapes from `describe_entry_schema` that tell an agent exactly what every field accepts, third-party fields included. Writes land as reviewable drafts with a control panel deep link, and `publish_entry` makes them live. The full format, workflow, and schema discovery are covered in the [Content Writing guide](docs/content-writing.md).

## The Toolbox

| Category | Highlights |
|----------|-----------|
| [Content](docs/tools/content.md) | Entries (payload-format read/write, draft workflow, publish/duplicate/copy to site), schema discovery via `describe_entry_schema`, assets, categories, users, globals |
| [System](docs/tools/system.md) | System info, config, logs, caches, routes, console commands |
| [Database](docs/tools/database.md) | Schema inspection, table counts, read-only queries |
| [Debugging](docs/tools/debugging.md) | Queue jobs, project config diff, deprecations, EXPLAIN, event handlers, and a Tinker tool for executing PHP in the Craft context |
| [Multi-Site](docs/tools/multisite.md) | Sites, site groups, per-site details |
| [GraphQL](docs/tools/graphql.md) | Schemas, SDL, query execution, tokens |
| [Backup](docs/tools/backup.md) | Create and list database backups |
| [Self-Awareness](docs/tools/mcp.md) | Plugin info, tool listing with risk annotations, hot reload |
| [Commerce](docs/tools/commerce.md) | Products, orders, and statuses (when Craft Commerce is installed) |

Tools that modify data or execute code are flagged `dangerous`: they sit behind the `enableDangerousTools` setting, carry a `destructiveHint` annotation in `tools/list`, and are excluded from `readonly` and (except entry workflow) `content` HTTP scopes. See the [Tools Overview](docs/tools/README.md) for the complete reference.

## Extending

Other plugins and modules can register their own tools, prompts, resources, and field translators through events (`EVENT_REGISTER_TOOLS`, `EVENT_REGISTER_FIELD_TRANSLATORS`, and friends). See the [Extending guide](docs/extending.md) for implementation details and examples.

## Documentation

The [documentation index](docs/README.md) links everything; the direct routes:

- **[Installation](docs/installation.md)** - Requirements, Composer setup, detailed installation steps
- **[Client Setup](docs/client-setup.md)** - Wizard and manual configs for Claude Code, Cursor, Claude Desktop
- **[Configuration](docs/configuration.md)** - All configuration options and security settings
- **[HTTP Transport](docs/http-transport.md)** - Remote access with per-user scoped bearer tokens
- **[Content Writing](docs/content-writing.md)** - The payload format, draft workflow, and schema discovery for agents
- **[Tools Reference](docs/tools/README.md)** - Complete documentation for every tool
- **[Prompts](docs/prompts.md)** - Pre-built analysis prompts
- **[Resources](docs/resources.md)** - Read-only URI-based access to schema, config, and content data
- **[Extending](docs/extending.md)** - Register custom tools, prompts, resources, and field translators

## Contributing

Thank you for considering contributing to Craft MCP! Please see [GitHub Issues](https://github.com/stimmtdigital/craft-mcp/issues) for bug reports, feature requests, and discussion.

## Credits

- Created and maintained by [Max van Essen](https://github.com/vanEssenMax)
- Inspired by [Laravel Boost](https://github.com/laravel/boost)
- Plugin icon from [Lucide](https://lucide.dev) (MIT)

## License

Craft MCP is open-sourced software licensed under the [MIT license](LICENSE).
