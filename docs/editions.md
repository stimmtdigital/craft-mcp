# Editions

Craft MCP comes in two editions. The rule is a single line: **everything that reads, inspects or queries your install is Lite. Everything that writes entry content is Pro.**

Switch editions in the Craft control panel under **Settings > Plugins**.

## What each edition includes

| | Lite | Pro |
|---|---|---|
| Read entries, assets, categories, users, globals | Yes | Yes |
| Schema discovery (`describe_entry_schema`) | Yes | Yes |
| Drafts and revision history (read) | Yes | Yes |
| GraphQL queries and schema | Yes | Yes |
| Read-only SQL and database inspection | Yes | Yes |
| Logs, queue, deprecations, project config diff | Yes | Yes |
| Multi-site aware reads | Yes | Yes |
| Craft Commerce reads | Yes | Yes |
| stdio and HTTP transports, scoped tokens | Yes | Yes |
| **Create and update entries** | No | Yes |
| **Publish, duplicate, delete entries** | No | Yes |
| **Create and reorder Matrix blocks** | No | Yes |
| **Copy entry content between sites** | No | Yes |

## The tools Pro adds

Pro adds exactly these eight, and nothing else:

| Tool | What it does |
|---|---|
| `create_entry` | Create an entry from a field payload |
| `update_entry` | Update an entry, optionally guarded by `expectedDateUpdated` |
| `publish_entry` | Apply a draft to its canonical entry |
| `delete_entry` | Move an entry to the trash |
| `duplicate_entry` | Duplicate an entry as an unpublished draft |
| `copy_entry_to_site` | Copy field values to another site as a draft |
| `create_nested_entry` | Add one Matrix block to a field |
| `move_nested_entry` | Reposition one Matrix block |

Every other tool is available on both editions. See the [Tools Reference](tools/README.md) for the full list.

## How Lite behaves

The content-writing tools are not advertised at all: they are absent from `tools/list`, so an agent never sees a tool it cannot use.

The server instructions adapt too. On Lite they gain an Edition section naming the missing tools and withdrawing the write guidance, because the base instructions teach a draft-first workflow that does not apply. An agent is never told to do something the install cannot do.

`list_mcp_tools` reports `requiredEdition` and `locked` for every tool, so a client can show its own upgrade prompt without hard-coding the list.

### Keeping locked tools visible

If you would rather the Pro tools stayed listed on a Lite install, turn on `showLockedProTools`:

```php
// config/mcp.php
return [
    'showLockedProTools' => true,
];
```

They then appear with `[Pro]` at the front of the description, require no arguments, and answer any call with:

> This tool requires the Pro edition of the Craft MCP plugin. The current edition does not include content-writing tools. Upgrade in the Craft control panel under Settings > Plugins.

Off by default, which keeps the tool list to what the install can actually do.

## For plugins that register their own tools

A plugin registering tools through `EVENT_REGISTER_TOOLS` can require an edition of its own with `#[RequiresEdition]`, on a method or on the whole class. A method-level attribute wins over a class-level one, and anything unmarked is Lite:

```php
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;

#[McpTool(name: 'my_tool', description: '...')]
#[RequiresEdition(Edition::Pro)]
public function myTool(): array
{
    // ...
}
```

The edition is checked after the settings, scope and permission checks, so a tool refused for any of those reasons reports that reason rather than advertising an upgrade that would not help. See [Extending](extending.md).
