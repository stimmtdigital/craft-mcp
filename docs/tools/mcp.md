# Self-Awareness Tools

Self-awareness tools let AI assistants understand the MCP plugin itself—its configuration, available tools, and runtime status. This is useful for discovering capabilities and troubleshooting integration issues.

## Plugin Information

### get_mcp_info

Get comprehensive information about the Craft MCP plugin, including version, configuration, and tool statistics.

**Parameters:** None

**Example:**

```
get_mcp_info
```

**Response:**

```json
{
  "name": "Craft MCP",
  "handle": "mcp",
  "version": "1.0.0",
  "schemaVersion": "1.0.0",
  "status": {
    "enabled": true,
    "dangerousToolsEnabled": false,
    "environment": "dev"
  },
  "tools": {
    "total": 45,
    "bySource": {
      "craft-mcp": 43,
      "my-plugin": 2
    },
    "byCategory": {
      "content": 10,
      "system": 7,
      "database": 4,
      "debugging": 7,
      "schema": 4,
      "multisite": 3,
      "graphql": 4,
      "backup": 2,
      "core": 3,
      "commerce": 6
    },
    "dangerous": 7,
    "errors": []
  },
  "configuration": {
    "disabledTools": ["tinker", "run_query"]
  }
}
```

Key sections:

- **status**: Shows whether the plugin and dangerous tools are enabled, plus the current environment
- **tools.bySource**: Shows where tools come from—`craft-mcp` for built-in tools, other sources for plugin-provided tools
- **tools.byCategory**: Tool count by functional category
- **tools.dangerous**: Count of tools marked as dangerous
- **tools.errors**: Any errors encountered during tool registration
- **configuration.disabledTools**: Tools explicitly disabled in config

---

## Tool Discovery

### list_mcp_tools

List all available MCP tools with their descriptions, categories, and enabled status. This is the definitive reference for what an AI assistant can do with your Craft installation.

**Parameters:** None

**Example:**

```
list_mcp_tools
```

**Response:**

```json
{
  "count": 45,
  "bySource": {
    "craft-mcp": 43,
    "my-plugin": 2
  },
  "byCategory": {
    "content": 10,
    "database": 4,
    "debugging": 7
  },
  "tools": [
    {
      "name": "list_entries",
      "description": "Query entries with filtering by section, status, author, and limit",
      "source": "craft-mcp",
      "category": "content",
      "dangerous": false,
      "enabled": true
    },
    {
      "name": "tinker",
      "description": "Execute arbitrary PHP code within your Craft application context",
      "source": "craft-mcp",
      "category": "debugging",
      "dangerous": true,
      "enabled": false
    },
    {
      "name": "myplugin_get_data",
      "description": "Get data from MyPlugin",
      "source": "my-plugin",
      "category": "plugin",
      "dangerous": false,
      "enabled": true
    }
  ]
}
```

Tools are sorted by source, then category, then name. The `enabled` field reflects both the dangerous tools setting and the `disabledTools` configuration—a tool shows as disabled if either condition prevents it from running.

---

## Hot Reload

### reload_mcp

Reload the MCP plugin to detect newly installed Craft plugins without restarting the MCP server process. This is useful during development when you're adding or updating plugins.

**Parameters:** None

**Example:**

```
reload_mcp
```

**Response:**

```json
{
  "success": true,
  "message": "MCP plugin state reloaded",
  "pluginsDiscovered": ["commerce", "seo", "my-plugin"],
  "tools": {
    "total": 51,
    "by_source": {
      "craft-mcp": 43,
      "my-plugin": 8
    },
    "by_category": {
      "content": 10,
      "plugin": 8
    },
    "dangerous": 7,
    "errors": []
  },
  "hint": "For code changes in existing plugins, send SIGHUP to the MCP server process: kill -HUP $(pgrep -f \"mcp-server\")"
}
```

**What gets reloaded:**

1. **Composer classmap**: Detects new plugin classes
2. **Plugin discovery cache**: Re-reads `vendor/craftcms/plugins.php`
3. **Project config cache**: Refreshes from YAML files
4. **Plugins service**: Reloads all Craft plugins
5. **Tool registry**: Re-collects tools from all sources

**Limitations:**

This tool performs a "soft" reload that can detect newly installed plugins. However, if you've made code changes to existing plugin files, those changes won't be picked up until the PHP process restarts. For code changes, send a SIGHUP signal to the MCP server:

```bash
kill -HUP $(pgrep -f "mcp-server")
```

This triggers a full process restart while maintaining the MCP connection.

## When to Use These Tools

- **get_mcp_info**: Start here to understand the current configuration and what's available
- **list_mcp_tools**: Reference for discovering specific tools and their purposes
- **reload_mcp**: After installing a new Craft plugin that provides MCP tools
