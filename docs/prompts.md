# Prompts

Prompts are pre-built conversation starters that help AI assistants perform complex analysis tasks on your Craft installation. Unlike tools that return raw data, prompts guide AI assistants through structured analysis workflows, providing context and asking the right questions.

When you select a prompt in your AI assistant, it gathers relevant data from your Craft installation and presents it along with specific analysis instructions. This helps the AI provide more insightful and actionable recommendations.

## Content Analysis

These prompts help you understand and maintain the health of your content.

### content_health_analysis

Analyze the overall health of your content, including entry statistics, status distribution, and content freshness across all sections.

**Parameters:** None

**What it analyzes:**

The prompt gathers data about every section in your installation:

- Entry counts by status (live, disabled, pending, expired)
- Draft counts and published ratios
- Last updated dates for each section
- Total content volume across the site

**Example output the AI receives:**

```json
{
  "summary": {
    "totalSections": 5,
    "totalLive": 234,
    "totalDisabled": 12,
    "totalDrafts": 8,
    "totalPending": 3,
    "totalExpired": 0
  },
  "sections": [
    {
      "section": "news",
      "name": "News",
      "live": 45,
      "disabled": 2,
      "pending": 1,
      "expired": 0,
      "drafts": 3,
      "total": 48,
      "lastUpdated": "2024-01-15 14:30:00"
    }
  ]
}
```

The AI then provides insights on content health scores, sections needing attention, freshness analysis, and maintenance recommendations.

---

### content_audit

Generate a comprehensive content audit report covering your content structure, field usage, and asset management.

**Parameters:** None

**What it analyzes:**

- All sections with entry counts and URL configuration
- Field type distribution across the installation
- Asset volume usage and file counts
- SEO considerations based on URL structure

**Use this prompt when:**

- Planning a content migration
- Reviewing content architecture decisions
- Preparing for a site redesign
- Auditing before a major content cleanup

---

### debug_content_issue

Get help debugging content-related issues with system context automatically included.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `issueDescription` | string | Yes | A description of the content issue you're experiencing |

**Example:**

```
debug_content_issue issueDescription="Entries in the 'products' section aren't showing their related categories"
```

**What it provides:**

The prompt includes your Craft version, PHP version, and dev mode status, then guides the AI through:

1. Identifying potential causes
2. Suggesting diagnostic steps
3. Recommending which tools to use (`read_logs`, `get_last_error`, etc.)
4. Providing solutions or workarounds

---

## Entry Management

These prompts help you work effectively with entries in specific sections.

### create_entry_guide

Get guidance on creating entries for a specific section, including required fields, validation rules, and example payloads.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | Yes | The section handle to get guidance for |
| `entryType` | string | No | Optionally filter to a specific entry type |

**Example:**

```
create_entry_guide section="blog" entryType="article"
```

**What it provides:**

The prompt gathers the section's complete field layout and presents:

- Required vs optional fields for each entry type
- Field types and validation constraints
- Example JSON payloads for the `create_entry` tool
- Common pitfalls to avoid

This is particularly useful when you need to create entries programmatically and want to understand the exact field structure.

---

### query_entries_guide

Get guidance on querying entries in a section with optimal performance.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | Yes | The section handle to query |

**Example:**

```
query_entries_guide section="products"
```

**What it provides:**

- Available filter parameters based on field types
- Pagination strategies for large result sets
- Performance optimization tips
- Example queries for common use cases

---

### bulk_entry_operations

Get guidance on performing bulk operations on entries in a section safely and efficiently.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | Yes | The section handle for bulk operations |

**Example:**

```
bulk_entry_operations section="news"
```

**What it provides:**

- Safe iteration patterns using pagination
- Batch update strategies
- Error handling and rollback considerations
- Performance tips for large datasets

---

## Schema Exploration

These prompts help you understand your content architecture and field configurations.

### explore_section_schema

Get detailed schema information about a section including all entry types, fields, and their configurations.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `section` | string | Yes | The section handle to explore |

**Example:**

```
explore_section_schema section="news"
```

**What it provides:**

The AI receives the complete section structure including:

- Entry types with their field layouts
- Field handles, types, and settings
- Relationship fields and their targets
- Matrix and nested field configurations

The AI then explains the section's purpose, describes content patterns, and suggests optimal ways to query or manage entries.

---

### field_usage_analysis

Analyze how a specific field is used across all sections and entry types.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `fieldHandle` | string | Yes | The field handle to analyze |

**Example:**

```
field_usage_analysis fieldHandle="featuredImage"
```

**What it provides:**

- Every location where the field is used
- Consistency analysis across usage contexts
- Suggestions for field optimization
- Potential issues with field configuration

This is useful for understanding field dependencies before making changes, or for auditing field usage patterns.

---

### explore_content_model

Get a comprehensive overview of your entire content model—all sections, entry types, and their field relationships.

**Parameters:** None

**What it provides:**

A complete map of your content architecture:

```json
[
  {
    "handle": "news",
    "name": "News",
    "type": "channel",
    "entryTypes": [
      {
        "handle": "article",
        "fieldCount": 8,
        "fields": [
          {"handle": "summary", "type": "PlainText"},
          {"handle": "body", "type": "Matrix"}
        ]
      }
    ]
  }
]
```

The AI then provides:

- An overview of the content architecture
- How different sections relate to each other
- Observations about field complexity
- Suggestions for content workflows

---

## Using Prompts

Prompts are available in AI assistants that support the MCP prompts capability. How you access them depends on your client:

**Claude Desktop:** Use the paperclip icon to select a prompt, or type `/` to search available prompts.

**Claude Code:** Prompts can be invoked through the MCP interface when your assistant supports prompt selection.

**Cursor:** Access prompts through the Composer's MCP integration panel.

Each prompt gathers fresh data from your Craft installation at the time it's invoked, ensuring you always get current information for analysis.
