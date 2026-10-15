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
    // 100 covers every tool the plugin registers in one page, so clients that
    // don't follow `nextCursor` pagination still see the full list. Raise it
    // further if you register enough external tools to exceed that.
    // Default: 100
    'paginationLimit' => 100,

    'entryWriteMode' => 'draft',

    // Largest file upload_asset accepts, in bytes, from a URL or as base64.
    // Default: 20971520 (20 MB)
    'assetUploadMaxBytes' => 20971520,

    // Whether entry writes return a previewUrl: a Craft preview-token link that
    // renders the draft as saved, for anyone holding it, until it expires.
    // Default: true
    'previewUrls' => true,
    // How long a previewUrl stays valid, in seconds. Null uses Craft's own
    // previewTokenDuration (falling back to defaultTokenDuration, a day).
    // Default: null
    'previewTokenDuration' => null,

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

    // Let Claude (web, Desktop, mobile) and other OAuth clients connect by URL:
    // the user logs in to the control panel and approves a scope. Needs
    // httpTransport and a public HTTPS site.
    // Default: false
    'oauth' => false,
    // Callback URLs an app may register. Registration is open, so this list is
    // what keeps a consent from being sent anywhere else. A loopback entry
    // matches any port.
    // Default: the Claude apps' callback and Claude Code's loopback callback
    'oauthRedirectUris' => [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
        'http://localhost/callback',
        'http://127.0.0.1/callback',
    ],
    // Lifetime of an OAuth access token, in seconds.
    // Default: 3600
    'oauthAccessTokenTtl' => 3600,
    // Lifetime of a refresh token, in seconds; each use replaces it.
    // Default: 2592000 (30 days)
    'oauthRefreshTokenTtl' => 2592000,

    // Install-introspection tools to allow scoped (readonly/content) HTTP tokens.
    // Privileged tools are locked to admins by default; site owners can open
    // specific ones here for their scoped token users.
    // Default: []
    'scopedTokenPrivilegedTools' => [
        'read_logs',
    ],

    // Scopes that cannot be minted on this install, no matter who is asking,
    // admins included. A deliberate per-environment guardrail rather than a
    // permission: existing tokens of a disabled scope keep working and can
    // still be regenerated, this only blocks minting new ones. Values must
    // exactly match a scope name: 'readonly', 'content', or 'full'.
    // Default: [] (every scope mintable)
    'disabledScopes' => [],

    // Text appended to the server instructions, on every transport, after
    // everything else the plugin adds. The only channel that reaches an
    // agent unconditionally on connect, so it's the place for guidance an
    // agent must know before its first write: a house style guide, staging
    // vs. production framing, an internal approval step.
    // Default: '' (nothing added)
    'additionalInstructions' => '',

    // Whether the token-reveal screen (My Account -> MCP Tokens) shows the
    // ready-to-paste Claude Code .mcp.json block alongside the new token.
    // Turn off on installs that provision MCP clients their own way.
    // Default: true
    'showClientConfigSnippet' => true,

    // Whether human-readable tool output (output="text") carries ANSI colour.
    // Off by default: the usual consumer is an AI client, which receives the
    // escape sequences as literal noise and pays tokens for them. Turn it on
    // when a person reads this server's output in a terminal.
    // Default: false
    'colorOutput' => false,
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
| `allowedIps` | `array` | `[]` | IP addresses or CIDR ranges (since 1.6.2) allowed to reach the HTTP endpoint; empty allows all |
| `logLevel` | `string` | `'error'` | Minimum log level for `storage/logs/mcp-server.log` |
| `paginationLimit` | `int` | `100` | Page size of MCP list endpoints (`tools/list`, `prompts/list`, `resources/list`); 100 covers every registered tool in one page. Useful when a client does not follow `nextCursor` pagination |
| `entryWriteMode` | `string` | `'draft'` | Since 1.4.0. Default save mode for entry writes: `'draft'` saves reviewable drafts, `'live'` saves immediately. Overridable per call via the `mode` param |
| `assetUploadMaxBytes` | `int` | `20971520` | Since 1.7.0. Largest file `upload_asset` accepts, in bytes (minimum 1024). See [Asset uploads](#asset-uploads) |
| `previewUrls` | `bool` | `true` | Since 1.6.0. Whether entry writes return a `previewUrl` that renders the draft as saved without a login. Each one stores a Craft token row that Craft removes once it expires |
| `previewTokenDuration` | `int\|null` | `null` | Since 1.6.0. Lifetime of a `previewUrl` in seconds (minimum 60). Null uses Craft's own `previewTokenDuration` |
| `httpTransport` | `bool` | `false` | Since 1.4.0. Whether the MCP server is also served over HTTP with per-user bearer tokens |
| `httpPath` | `string` | `'mcp'` | Since 1.4.0. Endpoint path on the primary site (no leading slash), used only when `httpTransport` is `true` |
| `httpSessionTtl` | `int` | `3600` | Since 1.4.0. HTTP session TTL in seconds; idle sessions are cleaned up after this long |
| `httpSessionStore` | `mixed` | `null` | Session storage for the HTTP transport. Null uses the built-in database-backed store; set a class name implementing `Mcp\Server\Session\SessionStoreInterface`, or a callable returning one, for a custom store |
| `httpPublicUrl` | `string\|null` | `null` | Since 1.4.0. Base URL for the endpoint in printed client snippets; set it on headless deployments where Craft answers on a different domain than the primary site. Since 1.6.0 it is also the OAuth issuer and the base of every OAuth URL |
| `oauth` | `bool` | `false` | Since 1.6.0. Lets Claude and other OAuth clients connect by URL, with the user approving a scope in the control panel. Requires `httpTransport`. See [Connecting Claude](http-transport.md#connecting-claude-web-desktop-and-mobile) |
| `oauthRedirectUris` | `array` | Claude apps + Claude Code | Since 1.6.0. Callback URLs an app may register; anything else is refused. A loopback (`http://localhost/...`, `http://127.0.0.1/...`) entry matches any port |
| `oauthAccessTokenTtl` | `int` | `3600` | Since 1.6.0. Lifetime of an access token issued over OAuth, in seconds (minimum 60) |
| `oauthRefreshTokenTtl` | `int` | `2592000` | Since 1.6.0. Lifetime of a refresh token, in seconds (minimum 3600). Refresh tokens rotate on every use |
| `scopedTokenPrivilegedTools` | `array` | `[]` | Since 1.4.0. Install-introspection tool names to allow scoped (readonly/content) HTTP tokens; privileged tools are locked to admins by default |
| `disabledScopes` | `array` | `[]` | Since 1.4.0. Scope names (`readonly`, `content`, `full`) closed on this install for everyone, admin included: they cannot be minted, and existing tokens of a disabled scope stop authenticating (rejected exactly like an unknown token) until the scope is re-enabled, which restores them without recreation. Regenerating an existing token stays possible so it survives the closure |
| `showLockedProTools` | `bool` | `false` | Whether tools above the running edition stay listed (marked `[Pro]`, answering any call with an upgrade message) instead of being hidden. See [Editions](editions.md) |
| `additionalInstructions` | `string` | `''` | Since 1.4.0. Text appended to the server instructions, on every transport (stdio and HTTP alike), after everything else the plugin adds |
| `showClientConfigSnippet` | `bool` | `true` | Since 1.4.0. Whether the token-reveal screen (My Account -> MCP Tokens) shows the ready-to-paste Claude Code `.mcp.json` block alongside the new token (a Claude Desktop config block before 1.6.0) |
| `colorOutput` | `bool` | `false` | Whether human-readable output (`output="text"`, including `read_logs`) carries ANSI colour. Off by default because escape sequences are noise to an AI client; see [Text Output](tools/README.md#text-output) |

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

`disabledScopes` follows the same per-environment pattern. For example, to allow self-service `readonly`/`content` tokens in production while keeping `full` unmintable there entirely, admins included:

```php
'production' => [
    'disabledScopes' => ['full'],
],
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
| `upload_asset` | Medium | Writes a file into a volume, fetched from a public URL or passed as base64 |
| `update_asset` | Medium | Changes an asset's metadata, filename or folder |
| `delete_asset` | Medium | Moves an asset to the trash |
| `create_user` | Medium | Creates a pending user account; full scope only |
| `update_user` | Medium | Changes a user's name or groups; full scope only |
| `set_user_status` | Medium | Activates, suspends or deactivates a user; full scope only |
| `create_backup` | Medium | Creates files on the server filesystem |
| `clear_caches` | Low | Can temporarily impact site performance |

When `enableDangerousTools` is `false`, these tools are hidden from AI assistants entirely. You can also disable them individually using the `disabledTools` array if you want finer control.

### Asset uploads

`upload_asset` can make the server fetch a URL it was handed. A server that fetches on request could otherwise be pointed at what only it can reach, such as a cloud metadata endpoint or an admin panel on localhost, so the fetch is held to these rules:

- `https` on port 443 only, with no credentials in the URL
- every address the host resolves to must be public: loopback, private, link-local, carrier-grade NAT and reserved ranges are refused
- the connection is pinned to the address that was checked, so a DNS answer that changes in between cannot redirect it
- no proxy is used for the fetch, since a proxy would resolve the name itself
- redirects are not followed
- the download stops once it passes `assetUploadMaxBytes`, and times out after 30 seconds

A server that may only reach the internet through a proxy cannot fetch by URL; `data` (base64) still works there. Uploads follow Craft's `allowedFileExtensions`, and on `content` tokens the token user's volume permissions.

### IP Allowlist

The `allowedIps` setting restricts which IP addresses can reach the HTTP endpoint. When configured, requests from other IPs are rejected with a 403. Entries are exact addresses or, since 1.6.2, CIDR ranges, IPv4 or IPv6; an entry that does not parse matches nothing:

```php
'allowedIps' => [
    '127.0.0.1',        // IPv4 localhost
    '::1',              // IPv6 localhost
    '192.168.1.100',    // A specific machine on your network
    '160.79.104.0/21',  // Anthropic, for the Claude connector
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
