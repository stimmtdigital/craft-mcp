# Extending Craft MCP

Craft MCP is designed to be extensible. Other Craft plugins and modules can register their own MCP tools, allowing AI assistants to interact with plugin-specific functionality just as they do with core Craft features.

This guide covers how to register custom tools, structure your tool classes, and follow best practices for AI-friendly responses.

## Why Extend Craft MCP?

If you maintain a Craft plugin, adding MCP tools lets AI assistants understand and work with your plugin's features. For example:

- An analytics plugin could expose tools to query page views, bounce rates, and conversion data
- A forms plugin could provide tools to list submissions, inspect form structures, and export data
- An e-commerce plugin could expose product catalogs, order histories, and inventory levels

This means users of your plugin can ask their AI assistant questions like "show me the top 10 pages by views this month" or "list all form submissions from the past week" and get accurate answers using your plugin's data.

## Plugin Integration

To register MCP tools from your plugin, listen to the `EVENT_REGISTER_TOOLS` event in your plugin's `init()` method. Always check that the Craft MCP plugin is installed before attempting to register tools:

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

        // Only register tools if Craft MCP is installed
        if (!class_exists(McpPlugin::class)) {
            return;
        }

        Event::on(
            McpPlugin::class,
            McpPlugin::EVENT_REGISTER_TOOLS,
            function(RegisterToolsEvent $event) {
                // Option 1: Register a single tool class
                $event->addTool(MyPluginTools::class, 'my-plugin');

                // Option 2: Register a discovery path to scan for tool classes
                $event->addDiscoveryPath(
                    __DIR__ . '/mcp',      // Directory containing tool classes
                    ['.', 'tools'],         // Subdirectories to scan
                    'my-plugin'             // Source identifier for logging
                );
            }
        );
    }
}
```

The `addTool()` method registers a single class, while `addDiscoveryPath()` scans a directory for any classes containing `#[McpTool]` attributes. Choose the approach that fits your plugin's structure.

## Module Integration

Craft modules can also register MCP tools using the same event. The pattern is identical to plugins:

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

This is particularly useful for project-specific modules that need to expose custom business logic to AI assistants.

## Writing Tool Classes

Tool classes are plain PHP classes with methods annotated using the `#[McpTool]` attribute from the MCP SDK. Each annotated method becomes an available tool that AI assistants can call.

Here's a complete example:

```php
<?php

declare(strict_types=1);

namespace myplugin\mcp\tools;

use Mcp\Capability\Attribute\McpTool;

class MyPluginTools
{
    /**
     * Get analytics data for a specific entry.
     *
     * This tool retrieves page view statistics and engagement metrics
     * for any entry in the Craft installation.
     */
    #[McpTool(
        name: 'myplugin_get_analytics',
        description: 'Get analytics data for a specific entry including page views and engagement metrics'
    )]
    public function getAnalytics(int $entryId, ?string $dateRange = '30d'): array
    {
        // Your implementation here
        $entry = \craft\elements\Entry::find()->id($entryId)->one();

        if ($entry === null) {
            return [
                'found' => false,
                'error' => "Entry {$entryId} not found",
            ];
        }

        // Fetch analytics data from your plugin's storage
        $analytics = $this->fetchAnalyticsForEntry($entry, $dateRange);

        return [
            'found' => true,
            'entry' => [
                'id' => $entry->id,
                'title' => $entry->title,
            ],
            'analytics' => $analytics,
            'dateRange' => $dateRange,
        ];
    }

    /**
     * List tracked analytics events with optional filtering.
     */
    #[McpTool(
        name: 'myplugin_list_events',
        description: 'List tracked analytics events with optional filtering by category'
    )]
    public function listEvents(?string $category = null, int $limit = 50): array
    {
        // Your implementation here
        $events = $this->fetchEvents($category, $limit);

        return [
            'count' => count($events),
            'category' => $category,
            'events' => $events,
        ];
    }

    // Private helper methods...
}
```

The `name` parameter defines how AI assistants will call the tool, while `description` helps them understand when to use it. Write clear, specific descriptions—they directly impact how well AI assistants choose the right tool for a task.

## Tool Metadata

Beyond the basic `#[McpTool]` attribute, you can add metadata to your tools using the `#[McpToolMeta]` attribute. This enables categorization, dangerous tool flagging, and conditional availability.

```php
<?php

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

class MyPluginTools
{
    #[McpTool(
        name: 'myplugin_get_data',
        description: 'Get data from MyPlugin'
    )]
    #[McpToolMeta(
        category: ToolCategory::PLUGIN,
        dangerous: false,
    )]
    public function getData(): array
    {
        // ...
    }

    #[McpTool(
        name: 'myplugin_delete_all',
        description: 'Delete all MyPlugin data'
    )]
    #[McpToolMeta(
        category: ToolCategory::PLUGIN,
        dangerous: true,
    )]
    public function deleteAll(): array
    {
        // This tool requires enableDangerousTools to be true
    }
}
```

### McpToolMeta Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `category` | ToolCategory | `GENERAL` | Logical grouping for the tool |
| `dangerous` | bool | `false` | Whether this tool can modify data or execute code |
| `condition` | string | `null` | Method name to call for conditional availability |

### Tool Categories

The `ToolCategory` enum provides these categories:

| Category | Use Case |
|----------|----------|
| `CONTENT` | Tools that work with entries, assets, categories, users |
| `SCHEMA` | Tools that inspect sections, fields, volumes |
| `SYSTEM` | Tools for configuration, logs, caches |
| `DATABASE` | Tools for database operations |
| `DEBUGGING` | Tools for troubleshooting |
| `MULTISITE` | Tools for multi-site management |
| `GRAPHQL` | Tools for GraphQL operations |
| `BACKUP` | Tools for database backups |
| `COMMERCE` | Tools for Craft Commerce |
| `CORE` | Internal MCP tools |
| `PLUGIN` | Tools provided by plugins (recommended for your tools) |
| `GENERAL` | Default category for uncategorized tools |

### Dangerous Tools

Tools marked as `dangerous: true` require the `enableDangerousTools` setting to be `true` in the MCP configuration. This protects against accidental data modification in production environments.

Mark a tool as dangerous if it:
- Modifies or deletes data
- Executes arbitrary code
- Creates files on the filesystem
- Makes external API calls that have side effects

### Method-Level Conditions

For fine-grained control, you can make individual tools conditionally available:

```php
#[McpTool(name: 'myplugin_premium_feature', description: '...')]
#[McpToolMeta(condition: 'isPremiumEnabled')]
public function premiumFeature(): array
{
    // ...
}

public function isPremiumEnabled(): bool
{
    return MyPlugin::getInstance()->settings->premiumEnabled;
}
```

The condition method must exist on the same class and return a boolean. If it returns `false`, the tool won't be registered.

## Conditional Tool Providers

For classes where all tools share a common availability condition, implement the `ConditionalToolProvider` interface. This is cleaner than adding conditions to every method.

```php
<?php

use stimmt\craft\Mcp\contracts\ConditionalToolProvider;

class CommerceTools implements ConditionalToolProvider
{
    public static function isAvailable(): bool
    {
        // Only register these tools if Commerce is installed
        return class_exists(\craft\commerce\Plugin::class)
            && \Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    #[McpTool(name: 'list_products', description: '...')]
    public function listProducts(): array
    {
        // This tool only exists if isAvailable() returns true
    }
}
```

The `isAvailable()` method is called during tool registration. If it returns `false`, the entire class is skipped—none of its tools are registered.

Common use cases:
- Tools that require a specific plugin (Commerce, SEO, etc.)
- Tools that require certain configuration to be present
- Tools that only make sense in specific environments

## Naming Conventions

**Always prefix your tool names** with your plugin handle to avoid conflicts with other plugins or future Craft MCP tools:

```php
// Good - clearly namespaced to your plugin
#[McpTool(name: 'myplugin_get_analytics', ...)]
#[McpTool(name: 'myplugin_list_events', ...)]
#[McpTool(name: 'myplugin_export_report', ...)]

// Bad - generic names may conflict with other plugins
#[McpTool(name: 'get_analytics', ...)]
#[McpTool(name: 'list_events', ...)]
#[McpTool(name: 'export', ...)]
```

Use `snake_case` for tool names to maintain consistency with Craft MCP's built-in tools.

## Response Patterns

Consistent response patterns help AI assistants understand and work with your tools more effectively. Follow these patterns based on what your tool is doing:

### Success Response

For operations that succeed:

```php
return [
    'success' => true,
    'data' => $data,
];
```

### Error Response

For operations that fail:

```php
return [
    'success' => false,
    'error' => 'A clear description of what went wrong',
];
```

### Single Record Lookup

When fetching a specific record by ID:

```php
// Record found
return [
    'found' => true,
    'record' => $data,
];

// Record not found
return [
    'found' => false,
    'error' => 'Record with ID 123 not found',
];
```

### List Response

When returning multiple items:

```php
return [
    'count' => count($items),
    'items' => $items,
];
```

### Paginated Response

When returning a subset of a larger result set:

```php
return [
    'count' => count($items),
    'total' => $totalCount,
    'limit' => $limit,
    'offset' => $offset,
    'items' => $items,
];
```

These patterns give AI assistants predictable structures to work with, making it easier for them to chain tool calls and report results to users.

## Security Best Practices

MCP tools have direct access to your plugin's functionality, so security is important. Follow these guidelines:

### 1. Validate All Inputs

Never trust data passed to your tools. Validate types, ranges, and formats:

```php
public function getRecord(int $id): array
{
    if ($id <= 0) {
        return [
            'success' => false,
            'error' => 'Invalid ID: must be a positive integer',
        ];
    }

    // Continue with valid input...
}
```

### 2. Avoid Shell Commands

Never use `exec()`, `shell_exec()`, `system()`, or similar functions in tool methods. These create serious security vulnerabilities.

### 3. Limit Data Exposure

Don't expose sensitive data like API keys, passwords, or internal configuration:

```php
// Bad - exposes sensitive data
return [
    'config' => $this->getSettings(), // May contain API keys
];

// Good - return only safe data
return [
    'config' => [
        'enabled' => $settings->enabled,
        'limit' => $settings->limit,
        // Omit sensitive fields
    ],
];
```

### 4. Prefer Read-Only Operations

Where possible, make your tools read-only. If you need tools that modify data, consider whether they should be marked as "dangerous" tools that users can disable.

### 5. Handle Errors Gracefully

Always catch exceptions and return structured error responses instead of letting errors bubble up:

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
        // Log the actual error for debugging
        \Craft::error("MCP tool error: " . $e->getMessage(), __METHOD__);

        // Return a safe error message to the AI
        return [
            'success' => false,
            'error' => 'An error occurred while fetching the record',
        ];
    }
}
```

## Event Reference

The `RegisterToolsEvent` class provides these methods for registering tools:

| Method | Description |
|--------|-------------|
| `addTool(string $class, string $source)` | Register a single tool class |
| `addDiscoveryPath(string $path, array $subdirs, string $source)` | Register a directory to scan for tool classes |
| `getTools(): array` | Get all registered tool classes |
| `getDiscoveryPaths(): array` | Get all registered discovery paths |
| `getErrors(): array` | Get any validation errors from registration |

## Tool Class Validation

When you register a tool class, Craft MCP validates it to ensure it will work correctly:

- The class must exist and be autoloadable
- The class must be instantiable (not abstract or an interface)
- The class must have at least one public method with the `#[McpTool]` attribute

If validation fails, the class is skipped and an error is logged. You can retrieve validation errors via `$event->getErrors()` for debugging.

## Documenting Your Tools

Help users get the most out of your MCP tools by documenting them in your plugin's README. Consider providing a `CLAUDE.md` snippet that users can add to their projects:

````markdown
## Claude Code Integration

Add to your project's `CLAUDE.md`:

```markdown
## MyPlugin MCP Tools

When working with analytics data:
- Use `myplugin_get_analytics` to fetch page view data for specific entries
- Use `myplugin_list_events` to understand user behavior patterns
- Always specify a `dateRange` parameter for accurate time-based comparisons
- Available date ranges: '7d', '30d', '90d', '1y'
```
````

This approach lets users opt-in to AI guidance for your tools without automatically modifying their project files.
