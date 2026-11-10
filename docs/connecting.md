# Connecting clients

Clients connect to **Craft's** MCP server; the plugin's `stimmt_` tools arrive alongside Craft's own. There are two ways in:

| Transport | For | How the user is known |
|-----------|-----|-----------------------|
| HTTP | Claude (web, Desktop, mobile), ChatGPT, Claude Code, Cursor, Codex and other remote clients | The person logs in to Craft and approves the connection |
| stdio | Local tools with a checkout of the project, scripts and CI | `php craft mcp/serve --user=…` runs as the named Craft user |

Before connecting over HTTP, finish [Installation](installation.md): Craft's MCP server on, endpoint paths that do not start with `mcp` inside the control panel, OAuth keys generated, and `php craft mcp/check` passing.

## The URL to give a client

Clients that sign in with OAuth use Craft's **Authorization Endpoint**: Settings → MCP, **Authenticated** tab. The full URL is your site or control panel URL followed by that path, for example `https://example.com/admin/ai/auth`.

Open the endpoint in a browser to see the exact URL, together with ready-made setup commands for several clients.

If the endpoint is inside the control panel, only users with control panel access can connect.

## Claude (web, Desktop and mobile)

1. In Claude's settings, open **Connectors** and choose **Add custom connector**.
2. Paste the URL and add the connector, then click **Connect**.
3. Craft's login page opens. Log in.
4. Craft asks what the app may do (see [Scopes](#scopes)). Choose, and click **Authorize**.

On Team and Enterprise plans an owner adds the custom connector for the organisation; each person then clicks **Connect** and logs in with their own Craft account. A connector added in Claude on the web is also available in the Desktop and mobile apps.

A short guide to send editors is in [Using Claude with your Craft site](claude-for-editors.md).

## Claude Code

Over HTTP, from the project or any directory:

```bash
claude mcp add --transport http craft https://example.com/admin/ai/auth
```

Then run `/mcp` inside Claude Code to log in to Craft and approve the connection.

Or run Craft's stdio server locally, with no login: see [stdio](#stdio-local-tools-scripts-and-ci).

## ChatGPT

ChatGPT connects to remote MCP servers as custom connectors, which it offers in developer mode. Add a connector with the URL above and choose OAuth as the authentication; ChatGPT then opens Craft's login and consent screens like Claude does.

ChatGPT connects from OpenAI's servers, so the site must be reachable on the public internet over HTTPS. A local `.ddev.site` or `localhost` address will not work.

## Other HTTP clients

Any MCP client that supports OAuth with dynamic client registration can connect with the same URL. Craft's endpoint page shows the commands for Codex, Gemini CLI, VS Code and Cursor.

Craft accepts redirects to any domain by default. To restrict which apps can register, set `redirectDomains` (and `customSchemes` for desktop apps) in `config/oauth.php`. Token lifetimes are set there too: `accessTokenDuration` (default one hour) and `refreshTokenDuration` (default 30 days).

## Scopes

When a person approves a connection, Craft offers three levels:

| Choice | Scope | `stimmt_` tools offered |
|--------|-------|-------------------------|
| **Read only** | `mcp:read` | `stimmt_entries_count`, `stimmt_refs_resolve`, `stimmt_entries_preview_url`, `stimmt_categories_list`, `stimmt_commerce_order_statuses_list`, `stimmt_commerce_product_types_list` |
| **Read and write** | `mcp:write` | The above, plus the Pro write tools: `stimmt_drafts_publish`, `stimmt_entries_copy_to_site`, `stimmt_nested_entries_create`, `stimmt_nested_entries_move` |
| **Full access** | `mcp:admin` | Everything, including the admin-only tools: database, logs, config, debugging, GraphQL, backups, `stimmt_tinker`, `stimmt_globals_list` and the Commerce product and order tools |

Full access is offered only to admin users. Within every level, each tool still checks the user's own Craft permissions, and the edition, `enableDangerousTools` and `disabledTools` still apply. The [Tools Reference](tools/README.md) lists the scope of every tool.

## Disconnecting

Each user sees their connected apps in the control panel under **My Account → Tokens**, and can revoke one there. The app loses access straight away.

## stdio: local tools, scripts and CI

Craft's stdio server runs as a Craft user you name by id, username or email:

```bash
php craft mcp/serve --user=ci@example.com
```

It has no scopes: the user's own rights decide. An admin user sees the admin-only tools; any user's content tools check that user's permissions. The edition, `enableDangerousTools` and `disabledTools` apply as usual. Craft refuses users that are inactive, suspended or locked.

For CI, create a dedicated Craft user with only the permissions the job needs.

`stimmt_console_commands_list` works only over stdio.

### The install wizard

`php craft mcp/install` writes stdio configs for Claude Code (`.mcp.json`), Cursor (`.cursor/mcp.json`), Claude Desktop and Codex (`.codex/config.toml`). Each one runs `php craft mcp/serve --user=…`, or `ddev exec php craft mcp/serve --user=…` in a DDEV project.

```bash
php craft mcp/install --user=anna
```

| Option | Alias | Purpose |
|--------|-------|---------|
| `--user` | `-u` | The Craft user the server acts as. Asked for when omitted, and checked before anything is written |
| `--environment` | `-e` | `ddev` or `native`. Detected when omitted |
| `--serverName` | `-s` | Name of the server entry in the client config. Default `craft-cms` |

Restart the client afterwards. Codex reads a project's `.codex/config.toml` only in a trusted project; the wizard prints the `codex mcp add` command for your user-level config instead.
