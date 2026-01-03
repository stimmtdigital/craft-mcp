<p align="center">
  <img src="src/icon.svg" alt="Craft MCP" width="100" height="100">
</p>

# Craft CMS MCP

[![CI](https://github.com/stimmtdigital/craft-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/stimmtdigital/craft-mcp/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-8.3%2B-8892BF.svg)](https://www.php.net/)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-E5422B.svg)](https://craftcms.com/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

MCP (Model Context Protocol) server for Craft CMS. Allows AI assistants like Claude to interact with your Craft installation.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Available Tools](#available-tools-32)
- [Usage with Claude Code](#usage-with-claude-code)
- [Examples](#examples)
- [Extending with Custom Tools](#extending-with-custom-tools)
  - [Plugin Integration](#plugin-integration)
  - [Module Integration](#module-integration)
  - [Tool Class Structure](#tool-class-structure)
  - [Security Considerations](#security-considerations)
  - [Providing CLAUDE.md Snippets](#providing-claudemd-snippets)
  - [Event Reference](#event-reference)
- [Support](#support)
- [License](#license)

## Requirements

- Craft CMS 5.0+
- PHP 8.3+

## Installation

```bash
# Add to composer.json repositories (for local development)
composer config repositories.craft-mcp path ./plugins/craft-mcp

# Require the plugin
composer require stimmt/craft-mcp

# Install in Craft
php craft plugin/install mcp
```

## Configuration

Create `config/mcp.php` in your Craft project:

```php
<?php

return [
    // Enable MCP server (disabled in production by default)
    'enabled' => true,

    // Enable dangerous tools (tinker, run_query, create_entry, update_entry, clear_caches)
    // Disabled in production by default
    'enableDangerousTools' => true,

    // Disable specific tools by name
    // 'disabledTools' => ['tinker', 'run_query'],

    // IP allowlist (empty = all allowed)
    // 'allowedIps' => ['127.0.0.1', '::1'],
];
```

**Security:** By default, MCP is disabled in production environments (`CRAFT_ENVIRONMENT=production`).

## Available Tools (32)

### Schema & Structure
| Tool | Description |
|------|-------------|
| `list_plugins` | List installed plugins with status and version |
| `list_sections` | List sections with entry types |
| `list_fields` | List custom fields with type and group |
| `list_volumes` | List asset volumes |
| `list_routes` | List registered routes |
| `list_console_commands` | List available console commands |

### Content
| Tool | Description |
|------|-------------|
| `list_entries` | Query entries with filters (section, type, status, limit) |
| `get_entry` | Get single entry by ID or slug |
| `create_entry` | Create new entry |
| `update_entry` | Update existing entry |
| `list_globals` | List global sets with field values |
| `list_categories` | List categories by group |
| `list_users` | List users with filters |

### Assets
| Tool | Description |
|------|-------------|
| `list_assets` | Query assets with filters (volume, kind, filename) |
| `get_asset` | Get single asset with metadata |
| `list_asset_folders` | List folders in a volume |

### System
| Tool | Description |
|------|-------------|
| `get_system_info` | Craft/PHP/DB versions and site info |
| `get_config` | Read config values by key |
| `read_logs` | Read recent log entries |
| `get_last_error` | Get most recent error from logs |
| `clear_caches` | Clear Craft caches |

### Database
| Tool | Description |
|------|-------------|
| `get_database_info` | Connection details |
| `get_database_schema` | List tables or table columns |
| `get_table_counts` | Row counts for core tables |
| `run_query` | Execute read-only SQL (SELECT only) |

### Tinker
| Tool | Description |
|------|-------------|
| `tinker` | Execute PHP code in Craft context (shell commands blocked) |

### Debugging
| Tool | Description |
|------|-------------|
| `get_queue_jobs` | List pending/failed/reserved queue jobs |
| `get_project_config_diff` | Show pending project config changes |
| `get_deprecations` | Get deprecation warnings from logs and database |
| `explain_query` | Run EXPLAIN on SQL for performance analysis |
| `get_environment` | Get safe environment info (no secrets) |
| `list_event_handlers` | List registered event listeners/hooks |

## Usage with Claude Code

Add to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "plugins/craft-mcp/bin/mcp-server"]
    }
  }
}
```

Or run manually:

```bash
# With DDEV
ddev exec php plugins/craft-mcp/bin/mcp-server

# Without DDEV
php plugins/craft-mcp/bin/mcp-server
```

## Examples

Query entries:
```
list_entries section="news" limit=10
```

Get config:
```
get_config key="general.devMode"
```

Run SQL:
```
run_query sql="SELECT id, title FROM entries LIMIT 5"
```

Tinker (execute PHP):
```
tinker code="Craft::$app->getVersion()"
tinker code="\craft\elements\Entry::find()->section('news')->count()"
```

Debug queue issues:
```
get_queue_jobs status="failed"
```

Check project config:
```
get_project_config_diff
```

Analyze query performance:
```
explain_query sql="SELECT * FROM entries WHERE sectionId = 1"
```

## Extending with Custom Tools

Other Craft plugins and modules can register their own MCP tools using the `EVENT_REGISTER_TOOLS` event.

### Plugin Integration

In your plugin's `init()` method:

```php
<?php

namespace myplugin;

use Craft;
use craft\base\Plugin;
use stimmt\craft\Mcp\Mcp as McpPlugin;
use stimmt\craft\Mcp\events\RegisterToolsEvent;
use yii\base\Event;

class MyPlugin extends Plugin
{
    public function init(): void
    {
        parent::init();

        // Only register if MCP plugin is installed and enabled
        if (!class_exists(McpPlugin::class)) {
            return;
        }

        Event::on(
            McpPlugin::class,
            McpPlugin::EVENT_REGISTER_TOOLS,
            function(RegisterToolsEvent $event) {
                // Option 1: Register individual tool classes (with validation)
                $event->addTool(MyPluginTools::class, 'my-plugin');

                // Option 2: Register a discovery path (for multiple tool classes)
                $event->addDiscoveryPath(
                    __DIR__ . '/mcp',           // Path to tool classes
                    ['.', 'tools'],              // Subdirectories to scan
                    'my-plugin'                  // Source identifier (your plugin handle)
                );
            }
        );
    }
}
```

### Module Integration

For Craft modules, register in your module's `init()`:

```php
<?php

namespace modules\mymodule;

use Craft;
use yii\base\Module;
use stimmt\craft\Mcp\Mcp as McpPlugin;
use stimmt\craft\Mcp\events\RegisterToolsEvent;
use yii\base\Event;

class MyModule extends Module
{
    public function init(): void
    {
        parent::init();

        // Check if MCP plugin exists
        if (!class_exists(McpPlugin::class)) {
            return;
        }

        Event::on(
            McpPlugin::class,
            McpPlugin::EVENT_REGISTER_TOOLS,
            function(RegisterToolsEvent $event) {
                $event->addDiscoveryPath(
                    __DIR__ . '/mcp',
                    ['tools'],
                    'my-module'
                );
            }
        );
    }
}
```

### Tool Class Structure

Tool classes use the `#[McpTool]` attribute from the MCP SDK:

```php
<?php

declare(strict_types=1);

namespace myplugin\mcp\tools;

use Mcp\Capability\Attribute\McpTool;

class MyPluginTools
{
    /**
     * Get analytics data for an entry.
     */
    #[McpTool(
        name: 'myplugin_get_analytics',
        description: 'Get analytics data for a specific entry'
    )]
    public function getAnalytics(int $entryId, ?string $dateRange = '30d'): array
    {
        // Your implementation here
        return [
            'entryId' => $entryId,
            'pageViews' => 1234,
            'dateRange' => $dateRange,
        ];
    }

    /**
     * List all tracked events.
     */
    #[McpTool(
        name: 'myplugin_list_events',
        description: 'List tracked analytics events with optional filtering'
    )]
    public function listEvents(?string $category = null, int $limit = 50): array
    {
        // Your implementation here
        return [
            'count' => 0,
            'events' => [],
        ];
    }
}
```

**Tool naming convention:** Prefix your tool names with your plugin handle to avoid conflicts (e.g., `myplugin_get_analytics` instead of just `get_analytics`).

### Security Considerations

When creating custom tools:

1. **Validate all inputs** - Never trust user-provided data
2. **Avoid shell commands** - Don't use `exec()`, `shell_exec()`, etc.
3. **Limit data exposure** - Don't expose sensitive configuration or credentials
4. **Use read-only operations** - Prefer read operations; mark write operations as dangerous
5. **Handle errors gracefully** - Return structured error responses, don't throw unhandled exceptions

Example of safe error handling:

```php
#[McpTool(name: 'myplugin_get_record', description: 'Get a record by ID')]
public function getRecord(int $id): array
{
    try {
        $record = MyRecord::findOne($id);

        if ($record === null) {
            return [
                'found' => false,
                'error' => "Record {$id} not found",
            ];
        }

        return [
            'found' => true,
            'record' => $record->toArray(),
        ];
    } catch (\Throwable $e) {
        Craft::error("MCP tool error: " . $e->getMessage(), __METHOD__);

        return [
            'success' => false,
            'error' => 'An error occurred while fetching the record',
        ];
    }
}
```

### Providing CLAUDE.md Snippets

To help AI assistants use your tools effectively, document a `CLAUDE.md` snippet in your plugin's README that users can optionally add to their project:

````markdown
## Claude Code Integration

Add to your project's `CLAUDE.md`:

```markdown
## MyPlugin MCP Tools

When working with analytics data:
- Use `myplugin_get_analytics` to fetch page view data before making content recommendations
- Use `myplugin_list_events` to understand user behavior patterns
- Always specify a `dateRange` parameter for accurate comparisons
```
````

This approach:
- Lets users opt-in to AI guidance
- Doesn't auto-modify project files
- Provides context that improves AI tool usage

### Event Reference

The `RegisterToolsEvent` provides these methods:

| Method | Description |
|--------|-------------|
| `addTool(string $class, string $source)` | Register a tool class with validation |
| `addDiscoveryPath(string $path, array $subdirs, string $source)` | Register a directory for tool discovery |
| `getTools(): array` | Get all registered tool classes |
| `getDiscoveryPaths(): array` | Get all registered discovery paths |
| `getErrors(): array` | Get validation errors from registration |

Tool classes are validated on registration:
- Class must exist
- Class must be instantiable (not abstract)
- Class must have at least one public method with `#[McpTool]` attribute

## Support

Have questions, found a bug, or want to request a feature?

- **Email:** [support@stimmt.digital](mailto:support@stimmt.digital)
- **Issues:** [GitHub Issues](https://github.com/stimmtdigital/craft-mcp/issues)

## Credits

- Created by [Max van Essen](https://github.com/vanEssenMax)
- Plugin icon based on [Bot](https://lucide.dev/icons/bot) from [Lucide](https://lucide.dev) (MIT License)

## License

MIT
