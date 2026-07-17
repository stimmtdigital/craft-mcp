# Configuration

Craft MCP is configured through a standard Craft config file at `config/mcp.php`. This guide covers all available options, environment variable support, and security best practices.

## Basic Configuration

At minimum, you need to enable the MCP server. Create `config/mcp.php` in your Craft project:

```php
<?php

return [
    'enabled' => true,
];
```

Production safety defaults are applied before this file is read: when `CRAFT_ENVIRONMENT` is `production`, `enabled` and `enableDangerousTools` both start out `false`. Values from `config/mcp.php` are then applied on top, key by key, and any key you set explicitly overrides the default for that key. In the minimal config above, only `enabled` is set, so in production the server turns on but `enableDangerousTools` stays at its safe default of `false`. To enable dangerous tools in production, set `'enableDangerousTools' => true` explicitly.

## Configuration Options

Here's a complete configuration file showing all available options with their default values:

```php
<?php

return [
    // Enable or disable the MCP server entirely.
    // Default: false in production, true in other environments
    'enabled' => true,

    // Enable tools that can modify data or execute code.
    // Default: false in production, true in other environments
    'enableDangerousTools' => true,

    // Disable specific tools by their snake_case name.
    // Useful for fine-grained control over what AI assistants can access.
    // Default: [] (no tools disabled)
    'disabledTools' => [
        'tinker',
        'run_query',
    ],

    // Disable specific prompts by name.
    // Default: [] (no prompts disabled)
    'disabledPrompts' => [],

    // Disable specific resources by their URI (including resource templates).
    // Default: [] (no resources disabled)
    'disabledResources' => [],

    // Restrict MCP connections to specific IP addresses.
    // When empty, connections from any IP are allowed.
    // Default: [] (all IPs allowed)
    'allowedIps' => [
        '127.0.0.1',
        '::1',
    ],

    // Minimum log level for the MCP server log (storage/logs/mcp-server.log).
    // Set to 'debug' to see tool invocations, arguments, and execution details.
    // Valid values: 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'
    // Default: 'error'
    'logLevel' => 'error',

    // Page size for MCP list endpoints (tools/prompts/resources list calls).
    // Raise this if a client does not follow `nextCursor` pagination.
    // Default: 50
    'paginationLimit' => 50,

    'entryWriteMode' => 'draft',

    // Serve the MCP server over HTTP with per-user bearer tokens, in addition to stdio.
    // Default: false
    'httpTransport' => false,

    // Endpoint path on the primary site (no leading slash), used only when httpTransport is true.
    // Default: 'mcp'
    'httpPath' => 'mcp',

    // HTTP session TTL in seconds; idle sessions are cleaned up after this long.
    // Default: 3600
    'httpSessionTtl' => 3600,

    // Session storage for the HTTP transport. Null uses the built-in
    // database-backed store (the mcp_sessions table), shared across app
    // instances. Set a class name implementing
    // Mcp\Server\Session\SessionStoreInterface, or a callable returning one,
    // to supply a custom store (for example Redis).
    // Default: null
    'httpSessionStore' => null,

    // Base URL clients reach the HTTP endpoint on, for the snippet printed by
    // mcp/tokens/create. Null derives it from the primary site, which is wrong
    // on headless deployments where Craft answers on a different domain.
    // Default: null
    'httpPublicUrl' => null,

    // Install-introspection tools to allow scoped (readonly/content) HTTP tokens.
    // Privileged tools are locked to admins by default; site owners can open
    // specific ones here for their scoped token users.
    // Default: []
    'scopedTokenPrivilegedTools' => [
        'read_logs',
    ],
];
```

### Option Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | `bool` | `false` (prod) / `true` (other) | Whether the MCP server accepts connections |
| `enableDangerousTools` | `bool` | `false` (prod) / `true` (other) | Whether tools that modify data or execute code are available |
| `disabledTools` | `array` | `[]` | List of tool names to disable regardless of other settings |
| `disabledPrompts` | `array` | `[]` | List of prompt names to disable |
| `disabledResources` | `array` | `[]` | List of resource URIs to disable |
| `allowedIps` | `array` | `[]` | IP addresses allowed to connect (empty = all allowed) |
| `logLevel` | `string` | `'error'` | Minimum log level for `storage/logs/mcp-server.log` |
| `paginationLimit` | `int` | `50` | Page size of MCP list endpoints (`tools/list`, `prompts/list`, `resources/list`). Useful when a client does not follow `nextCursor` pagination |
| `entryWriteMode` | `string` | `'draft'` | Since 1.4.0. Default save mode for entry writes: `'draft'` saves reviewable drafts, `'live'` saves immediately. Overridable per call via the `mode` param |
| `httpTransport` | `bool` | `false` | Since 1.4.0. Whether the MCP server is also served over HTTP with per-user bearer tokens |
| `httpPath` | `string` | `'mcp'` | Since 1.4.0. Endpoint path on the primary site (no leading slash), used only when `httpTransport` is `true` |
| `httpSessionTtl` | `int` | `3600` | Since 1.4.0. HTTP session TTL in seconds; idle sessions are cleaned up after this long |
| `httpSessionStore` | `mixed` | `null` | Session storage for the HTTP transport. Null uses the built-in database-backed store; set a class name implementing `Mcp\Server\Session\SessionStoreInterface`, or a callable returning one, for a custom store |
| `httpPublicUrl` | `string\|null` | `null` | Since 1.4.0. Base URL for the endpoint in printed client snippets; set it on headless deployments where Craft answers on a different domain than the primary site |
| `scopedTokenPrivilegedTools` | `array` | `[]` | Since 1.4.0. Install-introspection tool names to allow scoped (readonly/content) HTTP tokens; privileged tools are locked to admins by default |

See the [HTTP Transport guide](http-transport.md) for enabling remote access, minting tokens, and scopes.

## Environment Variables

You can use environment variables in your configuration using Craft's `App::env()` helper. This is useful for managing different settings across environments without changing code:

```php
<?php

use craft\helpers\App;

return [
    'enabled' => App::env('MCP_ENABLED') ?? false,
    'enableDangerousTools' => App::env('MCP_DANGEROUS_TOOLS') ?? false,
];
```

Then define the variables in your `.env` file:

```bash
# Enable the MCP server
MCP_ENABLED=true

# Enable dangerous tools (tinker, run_query, create_entry, etc.)
MCP_DANGEROUS_TOOLS=false
```

This approach keeps sensitive configuration out of version control and makes it easy to adjust settings per environment.

### Automatic Environment Detection

The plugin automatically adjusts its defaults based on the `CRAFT_ENVIRONMENT` environment variable, applied before `config/mcp.php` is read:

| Setting | Production | Other Environments |
|---------|------------|-------------------|
| `enabled` | `false` | `true` |
| `enableDangerousTools` | `false` | `true` |

These defaults apply whether or not `config/mcp.php` exists. Once the file is read, any key it sets explicitly overrides the default for that key; keys it doesn't mention keep the default shown above. This means you can safely deploy the plugin to production without worrying about accidentally exposing the MCP server or its dangerous tools: both stay off unless you explicitly turn them on.

## Multi-Environment Configuration

For more complex setups, `config/mcp.php` supports Craft's multi-environment config pattern: a top-level `'*'` key holding settings shared by every environment, merged with a block matching the current `CRAFT_ENVIRONMENT`. `Mcp::resolveEnvironmentConfig()` performs this merge; keys in the environment-specific block override the same key in `'*'`, and keys present in only one of the two pass through unchanged. A config file without a `'*'` key is treated as flat and applied as-is, with no per-environment interpretation.

```php
<?php

return [
    // Shared settings applied to all environments
    '*' => [
        'disabledTools' => ['tinker'],
    ],

    // Development environment - full access
    'dev' => [
        'enabled' => true,
        'enableDangerousTools' => true,
        'disabledTools' => [],
    ],

    // Staging environment - read-only access
    'staging' => [
        'enabled' => true,
        'enableDangerousTools' => false,
    ],

    // Production environment - disabled entirely
    'production' => [
        'enabled' => false,
    ],
];
```

This pattern allows you to grant full access during development, restricted access on staging for testing, and keep production locked down. As always, production safety defaults are applied first; the resolved `production` block above only needs to set what should differ from those defaults.

## Security Considerations

The MCP server provides powerful access to your Craft installation. While this is invaluable for AI-assisted development, it requires careful consideration in shared or production environments.

### Production Environments

By default, the MCP server is **completely disabled** when `CRAFT_ENVIRONMENT` is set to `production`. This is intentional: the server provides access to your content, configuration, and in some cases the ability to execute code.

If you have a specific need to enable MCP in production (for example, a protected staging server that uses `production` as its environment name), take these precautions:

1. **Use IP allowlisting** to restrict which machines can connect
2. **Disable dangerous tools** to prevent data modification
3. **Audit which tools you actually need** and disable the rest

### Dangerous Tools

The following tools are classified as "dangerous" because they can modify data or execute code. They are controlled by the `enableDangerousTools` setting:

| Tool | Risk Level | Description |
|------|------------|-------------|
| `tinker` | High | Executes arbitrary PHP code in your application context. Blocklist-based only, not a secure sandbox: it can be bypassed (e.g. `call_user_func`, variable functions) |
| `execute_graphql` | High | Executes GraphQL queries and mutations, which can modify data |
| `run_query` | Medium | Executes SQL queries (restricted to SELECT, but still exposes data) |
| `create_entry` | Medium | Creates new entries in your content |
| `update_entry` | Medium | Modifies existing entry content |
| `publish_entry` | Medium | Applies a draft to its canonical entry, or enables a disabled entry |
| `delete_entry` | Medium | Moves an entry to the trash |
| `duplicate_entry` | Medium | Creates a new draft entry from an existing one |
| `copy_entry_to_site` | Medium | Writes a draft on another site from an entry's field values |
| `create_backup` | Medium | Creates files on the server filesystem |
| `clear_caches` | Low | Can temporarily impact site performance |

When `enableDangerousTools` is `false`, these tools are hidden from AI assistants entirely. You can also disable them individually using the `disabledTools` array if you want finer control.

### IP Allowlist

The `allowedIps` setting restricts which IP addresses can connect to the MCP server. When configured, connection attempts from other IPs are rejected:

```php
'allowedIps' => [
    '127.0.0.1',      // IPv4 localhost
    '::1',            // IPv6 localhost
    '192.168.1.100',  // A specific machine on your network
],
```

This is particularly useful when running the MCP server on a shared development environment or a staging server that's accessible from the internet.

### Disabling Specific Tools

If you want to allow most tools but restrict a few specific ones, use the `disabledTools` array:

```php
'disabledTools' => [
    'tinker',           // No PHP execution
    'run_query',        // No direct SQL access
    'clear_caches',     // Don't let AI clear caches
],
```

Tools listed here are disabled regardless of the `enableDangerousTools` setting. Use the snake_case tool name as shown in the [Tools Reference](tools/README.md).

## Debugging

The MCP server writes logs to `storage/logs/mcp-server.log`, separate from Craft's own logging. By default, only errors are logged. To enable verbose logging for troubleshooting tool failures:

```php
'logLevel' => 'debug',
```

With debug logging enabled, the log includes:
- Tool invocations with name and arguments (from the MCP SDK)
- Tinker-specific events: code being executed, security pattern blocks, and caught errors
- Execution results and timing

After changing the log level, restart the MCP server (send SIGHUP to the process, or restart your AI client).

### Common Issues

**"Tool execution failed" with no detail**: This typically means an unexpected error occurred outside the tool's error handling. Check `storage/logs/mcp-server.log` for the full exception. Enable `'logLevel' => 'debug'` for additional context.

**Tinker returns no output**: Verify that `enableDangerousTools` is `true` and that `tinker` is not in the `disabledTools` array. Check with the `list_mcp_tools` tool to confirm tinker is listed as enabled.

## Next Steps

- **[HTTP Transport](http-transport.md)** - Serve the MCP server over HTTP with per-user scoped bearer tokens
- **[Tools Reference](tools/README.md)** - See all available tools and their capabilities
- **[Extending](extending.md)** - Learn how to add custom tools from your plugins
