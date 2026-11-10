# Installation

Craft CMS ships its own MCP server since 5.12. This plugin adds its tools to that server, so installing it takes two parts: the plugin itself, and Craft's MCP server switched on.

Upgrading from 1.x? Follow [UPGRADE.md](../UPGRADE.md) instead: 2.0 changes the config file, the endpoint and every tool name.

## Requirements

- Craft CMS 5.12.1 or later
- PHP 8.3 or later

## 1. Install the plugin

```bash
composer require stimmt/craft-mcp
php craft plugin/install mcp
```

The plugin handle is `mcp`.

### Local development of the plugin

To work on the plugin itself from a local checkout:

```bash
composer config repositories.craft-mcp path ./plugins/craft-mcp
composer require stimmt/craft-mcp:@dev
php craft plugin/install mcp
```

## 2. Turn on Craft's MCP server

In the control panel, go to **Settings → MCP**, open the **Authenticated** tab and enable the authenticated MCP server.

**Choose endpoint paths that do not start with `mcp` inside the control panel.** The plugin's handle is `mcp`, and Craft sends any control panel path whose first segment is a plugin handle to that plugin. A client calling such an endpoint gets the plugin's login page instead of the MCP server.

Craft's defaults run into this: with a `cpTrigger` and public registration off, the default endpoint is `/{cpTrigger}/mcp` (for example `/admin/mcp`); without a `cpTrigger` it is `/mcp` on the control panel's domain. Set instead:

| Setting | Value |
|---------|-------|
| Endpoint | `/admin/ai` (use your own `cpTrigger`) |
| Authorization Endpoint | `/admin/ai/auth` |

If the control panel has no `cpTrigger` (it runs on the root of its own domain), use `/ai` and `/ai/auth`.

The endpoints only conflict inside the control panel: on a site with a `cpTrigger`, a front-end path such as `/mcp` is not affected. `php craft mcp/check` tells you either way.

Craft keeps these settings in project config, so they travel with your deploy.

## 3. Generate OAuth keys

Craft signs the access tokens it gives MCP clients. It needs a key pair for that, once per environment:

```bash
php craft oauth/generate-keys
```

The keys are written to `storage/`, so they do not travel with project config: run the command on every environment. Alternatively, set the `privateKey` and `publicKey` settings in Craft's `config/oauth.php`. Without keys no client can finish connecting.

## 4. Check the setup

```bash
php craft mcp/check
```

It reports, line by line:

| Check | Fails when |
|-------|------------|
| Craft MCP server | Craft's authenticated MCP server is off (a warning: stdio still works) |
| Endpoint path | An endpoint starts with `mcp` inside the control panel (error) |
| OAuth keys | Craft has no signing key (error) |
| `config/mcp.php` | The file holds keys Craft's MCP server does not know, such as 1.x plugin settings (error) |
| Plugin tools | The plugin's tools are off in this environment (info) |
| Edition | Reports Lite or Pro |

Each failed check says what to change. The command exits non-zero when any check is an error, so a deploy script can stop on it. The plugin's settings screen in the control panel (**Settings → Plugins → Craft MCP**) shows the same report.

## 5. Connect a client

See [Connecting clients](connecting.md): Claude, ChatGPT and other HTTP clients connect to Craft's endpoint and log in; local tools such as Claude Code, Cursor and Codex can also run Craft's stdio server, which `php craft mcp/install` sets up.

## Troubleshooting

**The client gets a login page or HTML instead of the server.** An endpoint path starts with `mcp` inside the control panel. Change it under Settings → MCP (step 2).

**The login works but connecting fails.** Craft has no OAuth keys on this environment. Run `php craft oauth/generate-keys` (step 3).

**Craft throws an error about an unknown property in `config/mcp.php`.** That file belongs to Craft's MCP server since 5.12 and accepts only `instructions` and `filters`. Move plugin settings to `config/craft-mcp.php`. See [Configuration](configuration.md).

**A `stimmt_` tool is missing.** Check, in this order: the scope the user approved (read, write or admin), whether the user is an admin (developer tools are admin-only), the edition (Pro tools), `enableDangerousTools` (off in production by default) and `disabledTools`. The [Tools Reference](tools/README.md#who-sees-which-tool) lists every gate.
