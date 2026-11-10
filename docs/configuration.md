# Configuration

Two parts of the install are configured in different places:

| What | Where |
|------|-------|
| Craft's MCP server: on/off, endpoint paths, public server | **Settings → MCP** in the control panel (stored in project config) |
| Craft's MCP server instructions and filters | `config/mcp.php` (`instructions`, `filters` only) |
| Craft's OAuth: signing keys, token lifetimes, allowed redirect domains | `config/oauth.php` |
| This plugin | `config/craft-mcp.php` |

**Do not put plugin settings in `config/mcp.php`.** Since Craft 5.12 that file configures Craft's own MCP server, which fails on keys it does not know. `php craft mcp/check` reports any that are left there.

The plugin's settings screen in the control panel is read-only: it shows the same report as `php craft mcp/check` and points at `config/craft-mcp.php`.

## `config/craft-mcp.php`

All settings with their defaults:

```php
<?php

return [
    // Add the plugin's tools to Craft's MCP server at all.
    'enabled' => true,

    // Tool names, with the stimmt_ prefix, to leave out.
    'disabledTools' => [],

    // Offer the dangerous tools: stimmt_tinker, stimmt_db_query,
    // stimmt_graphql_execute and stimmt_backups_create.
    // Forced to false in production unless this file sets it.
    'enableDangerousTools' => true,

    // Largest response, in bytes, before a tool refuses and asks for a
    // narrower request. Minimum 1024.
    'maxResponseBytes' => 1048576,

    // How long a stimmt_entries_preview_url link stays valid, in seconds.
    // Minimum 60. null uses Craft's defaultTokenDuration.
    'previewTokenDuration' => null,
];
```

| Setting | Default | Effect |
|---------|---------|--------|
| `enabled` | `true` | `false` removes every `stimmt_` tool and the plugin's server instructions. Craft's MCP server keeps running without them |
| `disabledTools` | `[]` | Hides the named tools, for example `['stimmt_tinker', 'stimmt_db_query']` |
| `enableDangerousTools` | `true`, `false` in production | Hides the four tools marked dangerous in the [Tools Reference](tools/README.md) |
| `maxResponseBytes` | `1048576` | Largest response, in bytes, that `stimmt_logs_read`, `stimmt_db_query`, `stimmt_db_schema` and `stimmt_graphql_query` return. Above it they refuse and ask for a narrower request |
| `previewTokenDuration` | `null` | Lifetime of preview links. `null` falls back to Craft's `defaultTokenDuration` |

## Production

When the environment is `production`, `enableDangerousTools` starts out `false` before the config file is read. Only an explicit `enableDangerousTools` key for that environment turns the dangerous tools back on.

Production does not turn the plugin off. Craft's MCP server being on in production is already an admin's decision, and the content tools respect each user's Craft permissions. To keep the plugin's tools out of production entirely, set `'enabled' => false` there.

The environment is Craft's `CRAFT_ENVIRONMENT`; when it is not set, the plugin treats the install as `production`.

## Per environment

The file follows Craft's multi-environment format: a `'*'` block for every environment, merged with the block named after the current environment. A file without a `'*'` key is applied as-is everywhere.

```php
<?php

return [
    '*' => [
        'disabledTools' => ['stimmt_graphql_execute'],
    ],
    'dev' => [
        'disabledTools' => [],
    ],
    'staging' => [
        'enableDangerousTools' => false,
    ],
    'production' => [
        'enabled' => false,
    ],
];
```

A key in the environment block replaces the same key in `'*'`; it is not merged into it.

## Access control

Who may use which tool is decided by Craft, not by a plugin setting:

- **Login.** Any active Craft user can connect over HTTP. When the endpoint is inside the control panel, only users with control panel access can use it.
- **Scope.** The user picks read, read and write, or (admins only) full access when approving the connection. See [Connecting clients](connecting.md#scopes).
- **Permissions.** Every content tool checks the acting user's Craft permissions, over HTTP and over stdio alike.
- **Admin-only tools.** The developer tools (database, logs, config, GraphQL, backups, tinker), `stimmt_globals_list` and the Commerce product and order tools are offered only to admin users.

On top of that, `disabledTools`, `enableDangerousTools` and the edition decide which `stimmt_` tools exist at all.
