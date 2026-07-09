# HTTP Transport

By default, Craft MCP speaks to AI assistants over stdio: the client launches a local PHP process and talks to it directly. That works well when the assistant runs on the same machine as your Craft install, but it does not help a content editor who wants to connect Claude Desktop to a remote or production site with no local PHP, DDEV, or SSH access.

The HTTP transport solves this by serving the MCP server from an endpoint on your Craft site itself, authenticated with a per-user bearer token. Anyone with a valid token can point Claude Desktop (or any MCP client that supports Streamable HTTP) straight at your site's URL, with access scoped to exactly what their token allows.

## Enabling

The HTTP transport is **off by default**. Enable it in `config/mcp.php` alongside the path it should be served from:

```php
<?php

return [
    'enabled' => true,
    'httpTransport' => true,
    'httpPath' => 'mcp',
];
```

`httpPath` is the route on your primary site, with no leading slash (default: `mcp`). With the example above, the endpoint is:

```
https://your-site.com/mcp
```

Enabling `httpTransport` registers this route only; it has no effect if `enabled` is `false`, since the whole MCP server is disabled in that case.

## Minting a Token

Tokens are managed through the plugin's console commands, run on the server that hosts your Craft install:

```bash
php craft mcp/tokens/create --user=editor@example.com --scope=content --name="Anna's Laptop" --expires=90
```

| Option | Required | Description |
|--------|----------|-------------|
| `--user` | Yes | Email or username of the Craft user the token acts as |
| `--scope` | No | `readonly`, `content`, or `full` (default: `content`) |
| `--name` | No | Display name for the token, shown in `mcp/tokens/list` (default: `<username> token`) |
| `--expires` | No | Days until the token expires; omit for a token that never expires |

The command prints the token's plaintext value once, along with a ready-to-paste Claude Desktop configuration snippet:

```
Token created (shown once, store it now):

  mcp_A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0

Claude Desktop config (claude_desktop_config.json):
  {
    "mcpServers": {
      "craft-cms": {
        "url": "https://your-site.com/mcp",
        "headers": { "Authorization": "Bearer mcp_A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0" }
      }
    }
  }
```

"Shown once" means exactly that: only the hash of the token is stored, never the plaintext. If you lose it, there is no way to recover it; create a new token and revoke the old one.

## Scopes

Each token carries a scope that limits which tools the connected client can see and call. Think of it in editor terms: `readonly` is for someone who should only look around, `content` is for day-to-day editing work, and `full` is for developers and administrators who need everything, including tools that run code or touch the database directly.

| Scope | Access | Tool groups included |
|-------|--------|----------------------|
| `readonly` | Browse and inspect | All non-destructive tools: list/get entries, assets, categories, users, and globals; schema and structure inspection; system info, logs, and multi-site tools; read-only database and GraphQL queries; queue and debugging inspection |
| `content` | Everything in `readonly`, plus entry editing | Adds `create_entry`, `update_entry`, `publish_entry`, `delete_entry`, `duplicate_entry`, `copy_entry_to_site` |
| `full` | Everything | Adds code execution (`tinker`), SQL (`run_query`), GraphQL mutations (`execute_graphql`), cache clearing (`clear_caches`), and backups (`create_backup`) |

Scope is applied on top of the plugin's existing global settings (`enabled`, `enableDangerousTools`, `disabledTools`): a tool disabled globally stays disabled over HTTP regardless of scope.

## Connecting Claude Desktop

1. Open your Claude Desktop configuration file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
2. Add the server using the `url` and `headers` fields from the snippet printed by `mcp/tokens/create`:

```json
{
  "mcpServers": {
    "craft-cms": {
      "url": "https://your-site.com/mcp",
      "headers": {
        "Authorization": "Bearer mcp_A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0"
      }
    }
  }
}
```

3. Restart Claude Desktop.
4. Verify the connection: the `craft-cms` server should appear in Claude Desktop's connected servers list. Ask the assistant to run `list_sections`, a lightweight readonly tool available to every scope; a successful response confirms both the connection and the token's authentication.

## Managing Tokens

List every token, including which user it acts as, its scope, expiry, and last use:

```bash
php craft mcp/tokens/list
```

Revoke a token by name or id:

```bash
php craft mcp/tokens/revoke "Anna's Laptop"
```

Revocation and expiry both take effect immediately on the next request; there is no grace period. If a client is mid-session when its token is revoked or expires, its next request (even one carrying a valid session id) gets a 401 response rather than being served, since the token is checked before the session.

## Security

- **HTTPS only.** Bearer tokens are sent as plain headers; only use this transport behind TLS. Do not enable it on a plain HTTP site.
- **Tokens are credentials.** Treat a minted token like a password: store it in a secrets manager or your MCP client's own config, never in a repository or shared document.
- **Scope minimally.** Grant `readonly` or `content` by default and reserve `full` for developers who genuinely need code execution and direct database access.
- **Sessions are server-side files.** Each connection's session state is written under Craft's runtime storage path (`storage/runtime/mcp-sessions/`) and expires after `httpSessionTtl` seconds of inactivity (default: 3600). No session data is kept client-side beyond the `Mcp-Session-Id` header.

## Troubleshooting

### 401 Unauthorized

Returned when the bearer token is missing, malformed, unknown, or expired, or when the Craft user it belongs to is disabled or suspended. The response includes a `WWW-Authenticate: Bearer` header. Check `mcp/tokens/list` to confirm the token still exists and hasn't expired, and confirm the user account is enabled.

### 404 Not Found

Returned when the HTTP transport is disabled (`httpTransport` is `false`) or when the request path doesn't match `httpPath` exactly; in both cases the route is never registered, so Craft serves its regular 404 page. A JSON-shaped 404 from the endpoint itself appears only in the narrower case where `httpTransport` is `true` but the plugin's global `enabled` setting is `false`. Either way: confirm both settings in `config/mcp.php` and that the client's configured URL matches.

### 405 Method Not Allowed

Returned for `GET` requests. The endpoint does not support server-sent events or long-lived streaming connections; MCP clients must use `POST`. The response includes an `Allow` header listing the supported methods.

### Logs

The HTTP transport writes to the same log as stdio: `storage/logs/mcp-server.log`. Set `'logLevel' => 'debug'` in `config/mcp.php` for verbose output, including per-request tool dispatch. See [Configuration](configuration.md#debugging) for details.

## Next Steps

- **[Configuration](configuration.md)** - All configuration options, including the settings covered here
- **[Tools Reference](tools/README.md)** - See every tool and which category it belongs to
