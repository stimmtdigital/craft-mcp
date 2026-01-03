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

This enables the MCP server with sensible defaults for development environments. The server will automatically disable dangerous tools when running in production.

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

    // Restrict MCP connections to specific IP addresses.
    // When empty, connections from any IP are allowed.
    // Default: [] (all IPs allowed)
    'allowedIps' => [
        '127.0.0.1',
        '::1',
    ],
];
```

### Option Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | `bool` | `false` (prod) / `true` (other) | Whether the MCP server accepts connections |
| `enableDangerousTools` | `bool` | `false` (prod) / `true` (other) | Whether tools that modify data or execute code are available |
| `disabledTools` | `array` | `[]` | List of tool names to disable regardless of other settings |
| `allowedIps` | `array` | `[]` | IP addresses allowed to connect (empty = all allowed) |

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

The plugin automatically adjusts its defaults based on the `CRAFT_ENVIRONMENT` environment variable:

| Setting | Production | Other Environments |
|---------|------------|-------------------|
| `enabled` | `false` | `true` |
| `enableDangerousTools` | `false` | `true` |

This means you can safely deploy the plugin to production without worrying about accidentally exposing the MCP server—it's disabled by default unless you explicitly enable it.

## Multi-Environment Configuration

For more complex setups, you can use Craft's multi-environment config pattern to define different settings for each environment:

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

This pattern allows you to grant full access during development, restricted access on staging for testing, and keep production locked down.

## Security Considerations

The MCP server provides powerful access to your Craft installation. While this is invaluable for AI-assisted development, it requires careful consideration in shared or production environments.

### Production Environments

By default, the MCP server is **completely disabled** when `CRAFT_ENVIRONMENT` is set to `production`. This is intentional—the server provides access to your content, configuration, and in some cases the ability to execute code.

If you have a specific need to enable MCP in production (for example, a protected staging server that uses `production` as its environment name), take these precautions:

1. **Use IP allowlisting** to restrict which machines can connect
2. **Disable dangerous tools** to prevent data modification
3. **Audit which tools you actually need** and disable the rest

### Dangerous Tools

The following tools are classified as "dangerous" because they can modify data or execute code. They are controlled by the `enableDangerousTools` setting:

| Tool | Risk Level | Description |
|------|------------|-------------|
| `tinker` | High | Executes arbitrary PHP code in your application context |
| `run_query` | Medium | Executes SQL queries (restricted to SELECT, but still exposes data) |
| `create_entry` | Medium | Creates new entries in your content |
| `update_entry` | Medium | Modifies existing entry content |
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

## Next Steps

- **[Tools Reference](tools/README.md)** - See all available tools and their capabilities
- **[Extending](extending.md)** - Learn how to add custom tools from your plugins
