# Database Tools

Inspect the database behind the install. All of these are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Lite edition.

For content, prefer Craft's `elements_*` tools and [`stimmt_entries_count`](content.md#stimmt_entries_count). These tools are for custom plugin tables, aggregate SQL and diagnosis.

---

## stimmt_db_schema

List every table, or the details of one.

| Name | Type | Description |
|------|------|-------------|
| `table` | string | Table name, with or without the install's table prefix. Omit to list every table |

Without `table`: `driver`, `tablePrefix`, `count` and `tables` (each with `name` and `fullName`).

With `table`: `table`, `fullName`, `primaryKey`, `foreignKeys`, `columns` (name, type, dbType, phpType, allowNull, defaultValue, isPrimaryKey, autoIncrement, size) and `indexes`. An unknown table is refused.

---

## stimmt_db_info

Database driver, server version, server, port, database name, table prefix and charset. No parameters. The password is not returned.

---

## stimmt_db_table_counts

Row counts for Craft's main tables: elements, entries, assets, users, categories, tags, global sets, sections, entry types, fields, volumes and plugins. No parameters. A table that does not exist reports `count: null` with an error.

---

## stimmt_db_query

> Dangerous: hidden while `enableDangerousTools` is off, which is the default in production.

Run a read-only SQL query.

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `sql` | string | | The `SELECT` statement. Anything the read guard does not recognise as read-only is refused before it runs |
| `limit` | int | 100 | Row cap, added as a `LIMIT` clause only when the statement has none |

Returns the rows with the list envelope from the [Tools Reference](README.md#response-shape), plus `columns`.

The read guard is a keyword check, not a security boundary. Treat this tool as development-only.

---

## stimmt_db_explain

Run `EXPLAIN` on a `SELECT` to see the query plan: indexes used and estimated rows.

| Name | Type | Description |
|------|------|-------------|
| `sql` | string | The `SELECT` to explain. It is not executed, but anything the read guard does not recognise as read-only is refused anyway |
