# GraphQL Tools

Inspect Craft's GraphQL schemas and run queries against them. All of these are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Lite edition.

`stimmt_graphql_query` is a convenient way to read anything Craft's GraphQL API exposes (assets, categories, users, plugin element types) in exactly the shape you ask for.

---

## stimmt_graphql_schemas_list

List every GraphQL schema with its scope and whether it is the public schema. No parameters.

---

## stimmt_graphql_schema_get

Read one schema: the scope it grants and the types it exposes. A Craft schema prints hundreds of kilobytes of SDL, so it is never returned whole.

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Schema id, as `stimmt_graphql_schemas_list` reports it |
| `uid` | string | Schema uid, as an alternative to `id` |
| `type` | string | A type name from the index, such as `Query` or `EntryInterface`. Returns that type's SDL instead of the index |
| `field` | string | One field of `type`, such as `entries` on `Query`. Returns the SDL for that field and its arguments, which is how to read a type too large to return whole |

Without `type`, the response is an index of every type: name, kind, field count and SDL size in bytes.

---

## stimmt_graphql_query

Run a read-only GraphQL query. Mutations and subscriptions are rejected before execution.

| Name | Type | Description |
|------|------|-------------|
| `query` | string | The GraphQL document. Only query operations are accepted |
| `variables` | string | Variables as a JSON-encoded **string** |
| `operationName` | string | Which operation to run, when the document has several |
| `schemaId` | int | Schema to run against, which decides what the query can reach. Omit for the public schema |

When there is no public schema and no `schemaId`, the call is refused and asks for one.

---

## stimmt_graphql_execute

> Dangerous: hidden while `enableDangerousTools` is off, which is the default in production.

Run any GraphQL operation, mutations included, so it can create, change and delete data. Same parameters as `stimmt_graphql_query`.

---

## stimmt_graphql_tokens_list

List GraphQL tokens with their name, schema, enabled state and expiry date. No parameters. The token values are not returned.
