# Database Tools

Database tools provide direct access to your Craft installation's database for introspection and read-only queries. These tools are particularly useful for understanding data relationships, debugging content issues, and performing analysis that would be cumbersome through Craft's element APIs.

## Connection Information

Before running queries, it helps to understand how your database is configured. The connection information tool provides this context.

### get_database_info

Get details about your database connection, including the driver type, server version, and table prefix. This is useful context before writing queries or understanding compatibility constraints.

**Parameters:** None

**Example:**

```
get_database_info
```

**Response:**

```json
{
  "driver": "mysql",
  "serverVersion": "8.0.35",
  "server": "localhost",
  "port": 3306,
  "database": "craft_mysite",
  "tablePrefix": "craft_",
  "charset": "utf8mb4"
}
```

The `tablePrefix` is particularly important—most Craft installations use `craft_` as the prefix, which means the `entries` table is actually named `craft_entries`. You'll need to include this prefix in your SQL queries.

---

## Schema Information

Understanding table structures is essential before writing queries. These tools let you explore the database schema without needing direct database access.

### get_database_schema

Explore your database structure. When called without parameters, it lists all tables. When given a specific table name, it returns detailed column information including types, constraints, and indexes.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `table` | string | null | Table name (without prefix) to get details for |

**List all tables:**

```
get_database_schema
```

**Response:**

```json
{
  "driver": "mysql",
  "tablePrefix": "craft_",
  "count": 45,
  "tables": [
    { "name": "entries", "fullName": "craft_entries" },
    { "name": "assets", "fullName": "craft_assets" },
    { "name": "users", "fullName": "craft_users" }
  ]
}
```

**Get table details:**

```
get_database_schema table="entries"
```

**Response:**

```json
{
  "table": "entries",
  "fullName": "craft_entries",
  "primaryKey": ["id"],
  "columns": [
    {
      "name": "id",
      "type": "integer",
      "dbType": "int(11)",
      "allowNull": false,
      "isPrimaryKey": true,
      "autoIncrement": true
    },
    {
      "name": "sectionId",
      "type": "integer",
      "dbType": "int(11)",
      "allowNull": false,
      "isPrimaryKey": false
    }
  ],
  "indexes": [
    { "name": "PRIMARY", "columns": ["id"] },
    { "name": "idx_entries_sectionId", "columns": ["sectionId"] }
  ],
  "foreignKeys": { ... }
}
```

The detailed view shows you everything you need to write effective queries: column names, data types, which columns are indexed, and foreign key relationships to other tables.

---

### get_table_counts

Get a quick overview of how much content exists in your Craft installation. This returns row counts for all core Craft tables, giving you a sense of the site's scale at a glance.

**Parameters:** None

**Example:**

```
get_table_counts
```

**Response:**

```json
{
  "elements": { "label": "Total elements", "count": 1523 },
  "entries": { "label": "Entries", "count": 245 },
  "assets": { "label": "Assets", "count": 892 },
  "users": { "label": "Users", "count": 15 },
  "categories": { "label": "Categories", "count": 42 },
  "tags": { "label": "Tags", "count": 128 },
  "globalsets": { "label": "Global sets", "count": 3 },
  "sections": { "label": "Sections", "count": 8 },
  "entrytypes": { "label": "Entry types", "count": 12 },
  "fields": { "label": "Fields", "count": 35 },
  "volumes": { "label": "Volumes", "count": 2 },
  "plugins": { "label": "Plugins", "count": 5 }
}
```

This is often the first thing to check when getting familiar with a new Craft installation—it immediately tells you whether you're dealing with a small portfolio site or a large content-heavy application.

---

## Query Execution

Sometimes you need to query the database directly. The `run_query` tool provides this capability with safety guardrails to prevent accidental data modification.

### run_query

Execute read-only SQL queries against your database. This is classified as a dangerous tool because it can expose data, but it's restricted to SELECT statements only.

> **Note:** This tool can be disabled via configuration. See the [Configuration Guide](../configuration.md).

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `sql` | string | Required | The SQL SELECT query to execute |
| `limit` | int | 100 | Maximum number of rows to return |

**Security restrictions:**

The tool enforces several safety measures:

- Only `SELECT` queries are allowed
- Blocked keywords: `INSERT`, `UPDATE`, `DELETE`, `DROP`, `TRUNCATE`, `ALTER`, `CREATE`, `GRANT`, `REVOKE`
- A `LIMIT` clause is automatically added if your query doesn't include one

**Examples:**

```
# Get entries from a specific section
run_query sql="SELECT id, title FROM craft_entries WHERE sectionId = 1"

# Count images in the asset library
run_query sql="SELECT COUNT(*) as total FROM craft_assets WHERE kind = 'image'" limit=1

# Join entries with their elements for more context
run_query sql="SELECT e.id, el.title, el.slug FROM craft_entries e JOIN craft_elements el ON e.id = el.id WHERE e.sectionId = 2"
```

**Response:**

```json
{
  "success": true,
  "count": 5,
  "columns": ["id", "title"],
  "rows": [
    { "id": 1, "title": "First Entry" },
    { "id": 2, "title": "Second Entry" }
  ]
}
```

**Error response:**

```json
{
  "success": false,
  "error": "Only SELECT queries are allowed for safety."
}
```

## Working with Queries

A few tips for writing effective queries:

1. **Always include the table prefix.** Most Craft installations use `craft_` as the prefix, so the `entries` table becomes `craft_entries`. Check `get_database_info` if you're unsure.

2. **Explore the schema first.** Use `get_database_schema` to understand table structures before writing complex queries. Craft's database schema can be intricate, especially around elements and content tables.

3. **Keep queries simple when possible.** While complex joins work fine, simpler queries are easier to debug and understand.

4. **For performance analysis**, use the `explain_query` tool in the [Debugging Tools](debugging.md) section to understand how your query will be executed.
