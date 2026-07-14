# GraphQL Tools

GraphQL tools provide access to Craft's built-in GraphQL API. You can inspect schemas, list API tokens, and execute queries directly, useful for debugging GraphQL integrations or exploring what data is available through the API.

## Schemas

GraphQL schemas in Craft define what data is accessible through the API. Each schema has a scope that determines which sections, entry types, assets, and other elements can be queried.

### list_graphql_schemas

List all GraphQL schemas configured in your Craft installation, including their permission scopes.

**Parameters:** None

**Example:**

```
list_graphql_schemas
```

**Response:**

```json
{
  "count": 2,
  "schemas": [
    {
      "id": 1,
      "uid": "a1b2c3d4-...",
      "name": "Public Schema",
      "scope": ["sections.news:read", "sections.blog:read", "volumes.images:read"],
      "permissions": {
        "sections": {
          "news": ["read"],
          "blog": ["read"]
        },
        "volumes": {
          "images": ["read"]
        }
      },
      "isPublic": true
    },
    {
      "id": 2,
      "uid": "e5f6g7h8-...",
      "name": "Full Access",
      "scope": ["sections.*:read", "volumes.*:read", "usergroups.*:read"],
      "permissions": {
        "sections": {
          "*": ["read"]
        },
        "volumes": {
          "*": ["read"]
        },
        "usergroups": {
          "*": ["read"]
        }
      },
      "isPublic": false
    }
  ]
}
```

The `permissions` object transforms the raw scope strings into a readable structure. A wildcard (`*`) means access to all items of that type.

---

### get_graphql_schema

Get detailed information about a specific schema, including its full SDL (Schema Definition Language). The SDL shows exactly what types, queries, and fields are available.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | No | Schema ID (takes precedence if both provided) |
| `uid` | string | No | Schema UID |

At least one parameter must be provided.

**Examples:**

```
# Get by ID
get_graphql_schema id=1

# Get by UID
get_graphql_schema uid="a1b2c3d4-..."
```

**Response:**

```json
{
  "success": true,
  "schema": {
    "id": 1,
    "uid": "a1b2c3d4-...",
    "name": "Public Schema",
    "scope": ["sections.news:read"],
    "permissions": {
      "sections": {
        "news": ["read"]
      }
    },
    "isPublic": true,
    "sdl": "type Query {\n  entries(section: [String]): [EntryInterface]\n  ...\n}",
    "sdlLength": 15420
  }
}
```

The `sdl` field contains the complete schema definition, this can be quite large for schemas with broad access. The `sdlLength` field tells you how many characters the SDL contains.

---

## Query Execution

### query_graphql

Run a read-only GraphQL query against Craft's GraphQL API. Mutations and subscriptions are rejected before execution, so this tool is safe for browsing any GraphQL-exposed data (assets, categories, users, plugin types) with exactly the response shape you ask for. Use `get_graphql_schema` to discover the available types first.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `query` | string | Yes | The GraphQL query to execute |
| `variables` | string | No | JSON string containing query variables |
| `operationName` | string | No | Name of the operation (when the query contains multiple) |
| `schemaId` | int | No | Schema ID to use (defaults to the public schema) |

**Examples:**

```
# Simple query
query_graphql query="{ entries(section: \"news\", limit: 5) { title, slug } }"

# Query with variables
query_graphql query="query GetEntry($id: [QueryArgument]) { entry(id: $id) { title } }" variables='{"id": 123}'

# Using a specific schema
query_graphql query="{ entries { title } }" schemaId=2
```

**Response:**

Same shape as `execute_graphql` below: `{"success": true, "data": {...}, "errors": null}`.

**Rejected mutation:**

```
query_graphql query="mutation { save_news_default_Entry(title: \"x\") { id } }"
```

```text
Error: Only query operations are allowed here; 'mutation' requires execute_graphql (dangerous tools)
```

The rejection surfaces as an MCP error result (`isError: true`) carrying that message as text, not as a JSON body.

Every operation in the query is parsed into a GraphQL AST and checked before Craft executes anything: any operation whose type is not `query` (a `mutation` or `subscription`) fails the call outright, before it ever reaches Craft's GraphQL executor. Because the check happens at parse time rather than through the permissions layer, `query_graphql` has no code path to a data-modifying mutation, so it stays available regardless of the `enableDangerousTools` setting, unlike `execute_graphql`.

---

### execute_graphql

Execute a GraphQL query or mutation against your Craft installation. This tool lets AI assistants fetch data using the same API your front-end applications use. For read-only work, prefer `query_graphql`: it is always available, since mutations cannot reach it.

> **Note:** This is a dangerous tool that can modify data via mutations. It can be disabled via configuration.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `query` | string | Yes | The GraphQL query or mutation to execute |
| `variables` | string | No | JSON string containing query variables |
| `operationName` | string | No | Name of the operation (when query contains multiple) |
| `schemaId` | int | No | Schema ID to use (defaults to public schema) |

**Examples:**

```
# Simple query
execute_graphql query="{ entries(section: \"news\", limit: 5) { title, slug } }"

# Query with variables
execute_graphql query="query GetEntry($id: [QueryArgument]) { entry(id: $id) { title } }" variables='{"id": 123}'

# Using a specific schema
execute_graphql query="{ entries { title } }" schemaId=2
```

**Response:**

```json
{
  "success": true,
  "data": {
    "entries": [
      { "title": "First Post", "slug": "first-post" },
      { "title": "Second Post", "slug": "second-post" }
    ]
  },
  "errors": null
}
```

**Error response:**

```json
{
  "success": true,
  "data": null,
  "errors": [
    {
      "message": "Cannot query field \"nonexistent\" on type \"Entry\".",
      "locations": [{ "line": 1, "column": 30 }]
    }
  ]
}
```

Note that GraphQL errors are returned in the `errors` field while `success` remains true, this matches standard GraphQL behavior where partial results can be returned alongside errors.

---

## Tokens

GraphQL tokens are API keys that authenticate requests and associate them with a specific schema. Each token grants the permissions defined by its linked schema.

### list_graphql_tokens

List all GraphQL tokens with their associated schemas. Token values are not exposed for security, only metadata about the tokens.

**Parameters:** None

**Example:**

```
list_graphql_tokens
```

**Response:**

```json
{
  "count": 2,
  "tokens": [
    {
      "id": 1,
      "uid": "i9j0k1l2-...",
      "name": "Mobile App",
      "enabled": true,
      "expiryDate": null,
      "schema": {
        "id": 1,
        "name": "Public Schema"
      },
      "dateCreated": "2024-01-01 00:00:00",
      "dateLastUsed": "2024-01-15 14:30:00"
    },
    {
      "id": 2,
      "uid": "m3n4o5p6-...",
      "name": "Internal Tools",
      "enabled": true,
      "expiryDate": "2024-12-31 23:59:59",
      "schema": {
        "id": 2,
        "name": "Full Access"
      },
      "dateCreated": "2024-01-10 00:00:00",
      "dateLastUsed": null
    }
  ]
}
```

Key fields:

- **enabled**: Disabled tokens cannot authenticate requests
- **expiryDate**: When set, the token stops working after this date
- **dateLastUsed**: Helps identify unused tokens that could be cleaned up
- **schema**: Shows which permissions this token grants

The actual token value (the Bearer token used in API requests) is never exposed through this tool.
