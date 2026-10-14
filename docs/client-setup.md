# Client Setup

How to connect MCP clients to the stdio server, which runs Craft on your own machine. To use a remote or production site from the Claude apps (web, Desktop, mobile), add it as a custom connector instead: no local PHP, no config file, just the URL and a Craft login. See [Connecting Claude](http-transport.md#connecting-claude-web-desktop-and-mobile).

## Quick Setup (Recommended)

Run the interactive configuration wizard to generate config files for your MCP clients:

```bash
php craft mcp/install
```

The wizard will:

1. Detect your environment (DDEV or native PHP)
2. Let you select which clients to configure (Claude Code, Cursor, Claude Desktop, Codex)
3. Generate the appropriate configuration files automatically

It handles all the details: creating directories, setting correct paths for your environment, and warning you if a server with the same name already exists. Claude Desktop and Codex are offered but not preselected, so a non-interactive run writes only the project files for Claude Code and Cursor.

**Options:**

| Option | Alias | Description |
|--------|-------|-------------|
| `--environment` | `-e` | Override detected environment (`ddev` or `native`) |
| `--serverName` | `-s` | Custom server name (default: `craft-cms`) |

**Examples:**

```bash
# Interactive wizard with auto-detection
php craft mcp/install

# Force DDEV environment
php craft mcp/install --environment=ddev

# Use a custom server name
php craft mcp/install --serverName=my-craft-site

# Non-interactive mode (use defaults)
php craft mcp/install --interactive=0
```

## Manual Setup

### Claude Code

1. Create a new file called `.mcp.json` in your Craft project root
2. Add the server configuration based on your local environment:

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "php",
      "args": ["vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

### Cursor

1. Create a new file called `.cursor/mcp.json` in your Craft project root (create the `.cursor` directory if it doesn't exist)
2. Add the server configuration based on your local environment:

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ddev",
      "args": ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "php",
      "args": ["vendor/stimmt/craft-mcp/bin/mcp-server"]
    }
  }
}
```
</details>

### Claude Desktop

1. Open your Claude Desktop configuration file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
2. Add the server configuration. Unlike Claude Code and Cursor, Claude Desktop requires absolute paths for both the command and working directory:

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "/usr/local/bin/ddev",
      "args": ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"],
      "cwd": "/path/to/your/craft/project"
    }
  }
}
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "/usr/bin/php",
      "args": ["vendor/stimmt/craft-mcp/bin/mcp-server"],
      "cwd": "/path/to/your/craft/project"
    }
  }
}
```
</details>

You can find your absolute paths by running `which ddev` or `which php` in your terminal.

Tip: for Claude Desktop connecting to a remote install, add the site as a [custom connector](http-transport.md#connecting-claude-web-desktop-and-mobile) instead; it needs only the URL and a Craft login, no local PHP or absolute paths.

### Codex

> **Since 1.6.0** for the wizard.

Codex (the CLI and the IDE extension) reads MCP servers from TOML: `.codex/config.toml` in the project, which it only reads for projects you have marked as trusted, or `~/.codex/config.toml` for your user. The wizard writes the project file; it keeps every other line in that file as it was and replaces only its own `[mcp_servers.<name>]` table.

<details>
<summary>With DDEV (recommended for Craft projects)</summary>

```toml
[mcp_servers.craft-cms]
command = "ddev"
args = ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"]
cwd = "/absolute/path/to/your/project"
```
</details>

<details>
<summary>Without DDEV (native PHP)</summary>

```toml
[mcp_servers.craft-cms]
command = "php"
args = ["vendor/stimmt/craft-mcp/bin/mcp-server"]
cwd = "/absolute/path/to/your/project"
```
</details>

`cwd` is required: Codex starts servers from wherever it was launched, and the binary path is relative to the project.

To add it to your user-level config instead, run this from the project root:

```bash
codex mcp add craft-cms -- ddev exec php vendor/stimmt/craft-mcp/bin/mcp-server
```

### Remote Server via SSH (Not Recommended)

For development environments on remote servers, you can tunnel through SSH. Note that this approach is not recommended for production use due to security considerations; prefer the [HTTP transport](http-transport.md).

<details>
<summary>SSH tunnel configuration</summary>

```json
{
  "mcpServers": {
    "craft-cms": {
      "command": "ssh",
      "args": [
        "-t",
        "user@your-server.com",
        "cd /path/to/craft/project && php vendor/stimmt/craft-mcp/bin/mcp-server"
      ]
    }
  }
}
```

Requirements:

- SSH key authentication must be configured (no password prompts)
- The remote server must have PHP 8.3+ available
- The Craft MCP plugin must be installed on the remote installation

</details>
