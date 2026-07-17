# HTTP Transport

> **Since 1.4.0.** Everything on this page requires Craft MCP 1.4.0 or later.

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

### Control Panel

Users can mint tokens for themselves via the control panel's My Account screen. Navigate to **My Account** and select **MCP Tokens** from the sidebar, then click **New Token**. Fill in:

- **Name** (required): A display name for this token (e.g., "Anna's Laptop")
- **Scope**: `readonly` or `content` (default: `content`). Self-service users cannot mint `full`-scope tokens; admins or token managers can.
- **Expires in**: Optional number of days until the token expires; leave blank for a token that never expires.

Click **Create** and the plaintext token appears once, along with a ready-to-paste Claude Desktop configuration snippet:

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

Copy and store the plaintext token securely; only the hash is saved in Craft. If you lose it, create a new token and revoke the old one.

Users with the **Manage all users' MCP tokens** permission can mint `readonly` and `content` tokens for any user via the same screens. Minting a `full`-scope token requires an admin account, since full scope bypasses all read and write authorization and includes code execution.

### Console

Tokens can also be minted via console command on the server:

```bash
php craft mcp/tokens/create --user=editor@example.com --scope=content --name="Anna's Laptop" --expires=90
```

| Option | Required | Description |
|--------|----------|-------------|
| `--user` | Yes | Email or username of the Craft user the token acts as |
| `--scope` | No | `readonly`, `content`, or `full` (default: `content`) |
| `--name` | No | Display name for the token, shown in `mcp/tokens/list` (default: `<username> token`) |
| `--expires` | No | Days until the token expires; omit for a token that never expires |

The command prints the same plaintext value and Claude Desktop snippet.

## Scopes

Each token carries a scope that limits which tools the connected client can see and call. Think of it in editor terms: `readonly` is for someone who should only look around, `content` is for day-to-day editing work, and `full` is for developers and administrators who need everything, including tools that run code or touch the database directly.

| Scope | Access | Tool groups included |
|-------|--------|----------------------|
| `readonly` | Browse and inspect | All non-destructive tools: list/get entries, assets, categories, users, and globals; schema and structure inspection; system info, logs, and multi-site tools; read-only database and GraphQL queries; queue and debugging inspection |
| `content` | Everything in `readonly`, plus entry editing | Adds `create_entry`, `update_entry`, `publish_entry`, `delete_entry`, `duplicate_entry`, `copy_entry_to_site` |
| `full` | Everything | Adds code execution (`tinker`), SQL (`run_query`), GraphQL mutations (`execute_graphql`), cache clearing (`clear_caches`), and backups (`create_backup`) |

Scope is applied on top of the plugin's existing global settings (`enabled`, `enableDangerousTools`, `disabledTools`): a tool disabled globally stays disabled over HTTP regardless of scope.

### User permissions

Readonly and content-scope tokens respect the linked Craft user's real permissions on both reads and writes. The permission checks are enforced live per request; revoking a user's control panel permissions takes effect immediately on the next request with no token rotation delay or grace period.

#### Reads

On readonly and content scopes, element reads are bounded by the acting user's view permissions:

- **Entry reads** (`list_entries`, `count_entries`, `get_entry`) are restricted to sections the user can view (checked via `viewEntries:<section-uid>` permission). Entries in other sections are filtered from lists and single `get_entry` calls on non-viewable entries are refused.
- **Asset reads** (`list_assets`, `get_asset`) are restricted to volumes the user can view (`viewAssets:<volume-uid>`).
- **Category reads** (`list_categories`) are restricted to groups the user can view (`viewCategories:<group-uid>`).
- **User reads** (`list_users`) require the `View Users` permission; users lacking it see only themselves.
- **Revision and draft reads** (`list_revisions`, `list_drafts`) inherit the same view scoping as their parent entry elements.

Install-introspection tools (read_logs, get_config, get_database_schema, get_database_info, get_table_counts, get_project_config_diff, get_environment) are locked to admins over readonly and content-scope tokens by default. The site owner can selectively open specific tools via the `scopedTokenPrivilegedTools` configuration setting:

```php
'scopedTokenPrivilegedTools' => [
    'read_logs',        // Allow this token user to read MCP server logs
    'get_config',       // Allow this token user to view Craft config
],
```

Tools named in this array are shown to that scope's users; tools absent from the array stay hidden. Admin-linked tokens and full-scope tokens bypass this restriction and always see privileged tools. Scope remains the outer ceiling: a tool must be included in the token's scope to be callable at all.

MCP resources follow the same rules as their tool equivalents: `craft://entries/{section}` and `craft://entries/{section}/{slug}` are bounded by the acting user's view permissions exactly like `list_entries` and `get_entry`, and every `craft://config/*` resource is locked to admins over readonly and content-scope tokens, matching `get_config`.

#### Writes

Content-scope tokens respect the linked Craft user's real permissions on entry writes. When a token with `content` scope creates, updates, publishes, deletes, duplicates, or copies an entry to another site, the write is checked against the token's user's actual permissions in the control panel through Craft's element authorization.

This means that multi-site access, peer/draft visibility, and publishing permissions all behave exactly as they do in the control panel. If the user doesn't have permission to edit entries in a section on a given site, a write targeting that section on that site is refused.

If a write is refused, the error response carries a message like:

```
This token's user 'editor' is not allowed to save this entry on site 'default' (Craft user permissions, checked live). Ask an admin to widen the user's permissions or mint a token for a user who has them.
```

#### Full scope and stdio

Full-scope tokens are deliberately exempt from all permission checks: they carry code execution capability, so they trust the token holder completely and skip Craft permission lookups. Stdio connections have no token identity and are never permission-scoped.

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

The printed `url` host comes from the primary site's base URL unless the `httpPublicUrl` setting is set. On headless deployments where the frontend and the CMS live on different domains, set `httpPublicUrl` to the domain where Craft itself answers (typically the control panel's domain) so every printed snippet is correct as-is:

```php
'httpPublicUrl' => 'https://cms.example.com',
```

## Managing Tokens

### Control Panel

Users can revoke their own tokens via **My Account > MCP Tokens**. Each token row shows its name, scope, expiry date (if set), and last used time; click **Revoke** to delete it immediately.

Admins and users with the **Manage all users' MCP tokens** permission can access a **Utilities > MCP Tokens** panel that lists every token across all users, with the user's name, scope, expiry, and last used time. Click **Manage** on any user row to view and revoke their tokens, or use **Revoke** inline to delete a token immediately.

### Console

Tokens can also be managed via console command:

```bash
php craft mcp/tokens/list
```

Lists every token, including which user it acts as, its scope, expiry, and last use.

```bash
php craft mcp/tokens/revoke "Anna's Laptop"
```

Revoke a token by name or id.

Revocation and expiry both take effect immediately on the next request; there is no grace period. If a client is mid-session when its token is revoked or expires, its next request (even one carrying a valid session id) gets a 401 response rather than being served, since the token is checked before the session.

## Security

- **HTTPS only.** Bearer tokens are sent as plain headers; only use this transport behind TLS. Do not enable it on a plain HTTP site.
- **Tokens are credentials.** Treat a minted token like a password: store it in a secrets manager or your MCP client's own config, never in a repository or shared document.
- **Scope minimally.** Grant `readonly` or `content` by default and reserve `full` for developers who genuinely need code execution and direct database access.
- **Scope is the outer ceiling; Craft permissions are the inner boundary.** A tool must be in the token's scope to be callable, but a `content` token's entry writes are also gated by the linked user's real Craft permissions. Full-scope tokens skip Craft permission checks (they carry code execution, so they trust the token holder completely). See [User permissions](#user-permissions) for details.
- **IP allowlisting applies.** When the plugin's `allowedIps` setting is non-empty, the endpoint rejects requests from any other IP with a 403 before authentication runs. An empty list (the default) allows all IPs.
- **Sessions are server-side.** Each connection's session state is stored in the `mcp_sessions` database table and expires after `httpSessionTtl` seconds of inactivity (default: 3600). No session data is kept client-side beyond the `Mcp-Session-Id` header.

## Load balancers and multiple instances

Sessions are stored in the `mcp_sessions` table, so they are shared across every app instance behind a load balancer: a request can be served by any instance regardless of which one handled the connection's earlier requests. Earlier versions stored sessions as instance-local files under `storage/runtime/mcp-sessions/`, which broke on multi-instance deployments since a session created on one instance was invisible to the others.

If you need a different backend (for example Redis), set `httpSessionStore` in `config/mcp.php` to a class name implementing `Mcp\Server\Session\SessionStoreInterface`, or a callable that returns one. See [Configuration](configuration.md) for details. Tracked in issue [#41](https://github.com/stimmtdigital/craft-mcp/issues/41).

## Control panel on the root domain

Some installs serve the control panel from the root of its own domain (`cpTrigger` set to `null` plus a `baseCpUrl` such as `https://cms.example.com`). On such a host every request counts as a control panel request, and the plugin registers the endpoint as a CP URL rule so it stays reachable.

One Craft behavior cannot be worked around: for guests, Craft intercepts any CP request whose first URL segment matches a plugin handle before routing runs, and the default `httpPath` (`mcp`) is exactly this plugin's handle. With the control panel on the root domain you must therefore pick a different path in `config/mcp.php`, and avoid paths that shadow real control panel routes (`entries`, `settings`, and so on):

```php
'httpPath' => 'mcp-http',
```

The token reveal and the console command build their endpoint URL from `httpPublicUrl` (or the primary site URL) plus `httpPath`, so once the path is configured the printed URL is correct. On plugin versions without CP rule support, `https://cms.example.com/index.php?action=mcp/http/handle` reaches the endpoint as an action request and works with the same bearer token.

## Troubleshooting

### 401 Unauthorized

Returned when the bearer token is missing, malformed, unknown, or expired, or when the Craft user it belongs to is disabled or suspended. The response includes a `WWW-Authenticate: Bearer` header. Check `mcp/tokens/list` to confirm the token still exists and hasn't expired, and confirm the user account is enabled.

### 403 Forbidden

Returned when the plugin's `allowedIps` setting is non-empty and the request comes from an IP that is not listed. Check the setting in `config/mcp.php` and remember that proxies can change the client IP Craft sees.

### 404 Not Found

Returned when the HTTP transport is disabled (`httpTransport` is `false`) or when the request path doesn't match `httpPath` exactly; in both cases the route is never registered, so Craft serves its regular 404 page. A JSON-shaped 404 from the endpoint itself appears only in the narrower case where `httpTransport` is `true` but the plugin's global `enabled` setting is `false`. Either way: confirm both settings in `config/mcp.php` and that the client's configured URL matches.

### 405 Method Not Allowed

Returned for `GET` requests. The endpoint does not support server-sent events or long-lived streaming connections; MCP clients must use `POST`. The response includes an `Allow` header listing the supported methods.

### Logs

The HTTP transport writes to the same log as stdio: `storage/logs/mcp-server.log`. Set `'logLevel' => 'debug'` in `config/mcp.php` for verbose output, including per-request tool dispatch. See [Configuration](configuration.md#debugging) for details.

## Next Steps

- **[Configuration](configuration.md)** - All configuration options, including the settings covered here
- **[Tools Reference](tools/README.md)** - See every tool and which category it belongs to
