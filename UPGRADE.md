# Upgrading from 1.x to 2.0

Craft CMS 5.12 ships its own MCP server. Craft MCP 2.0 is no longer a server of its own: it adds its tools to Craft's. Craft now provides the HTTP endpoint, the OAuth login and stdio. The plugin adds what Craft's tools do not cover: developer tools, categories and globals, Commerce, counting, natural keys, preview links, validated publishing and the single Matrix-block tools.

This is a breaking update. Go through the steps below on every environment.

## Before you update

- **Craft 5.12.1 or later.** Update Craft first, or together with the plugin:

  ```bash
  composer update craftcms/cms stimmt/craft-mcp -W
  php craft up
  ```

- **Every 1.x token and connection stops working.** The update drops the 1.x tables (`mcp_tokens`, `mcp_sessions`, `mcp_oauth_*`). Static `mcp_` tokens have no replacement: Craft does not let personal access tokens use its MCP server. Clients reconnect through Craft's own OAuth login, and scripts and CI switch to stdio (see step 4).

## 1. Move the plugin's settings to `config/craft-mcp.php`

Since 5.12, `config/mcp.php` configures Craft's own MCP server. It accepts only `instructions` and `filters`, and fails on anything else. Rename your 1.x file and keep only the settings 2.0 still has:

| Setting | 2.0 |
|---|---|
| `enabled`, `disabledTools`, `enableDangerousTools`, `maxResponseBytes`, `previewTokenDuration` | kept. `disabledTools` takes the new `stimmt_` names |
| `httpTransport`, `httpPath`, `httpPublicUrl`, `httpSessionTtl`, `httpSessionStore`, `allowedIps`, `oauth`, `oauthRedirectUris`, `oauthAccessTokenTtl`, `oauthRefreshTokenTtl`, `disabledScopes`, `scopedTokenPrivilegedTools`, `showClientConfigSnippet` | removed: Craft's MCP server handles the endpoint, logins and scopes (Settings → MCP, and Craft's `config/oauth.php`) |
| `additionalInstructions` | removed: use `instructions` in Craft's `config/mcp.php` |
| `paginationLimit`, `showLockedProTools`, `colorOutput`, `disabledPrompts`, `disabledResources`, `entryWriteMode`, `previewUrls`, `assetUploadMaxBytes`, `logLevel` | removed |

Production now forces off only the dangerous tools (`enableDangerousTools`), no longer the whole plugin. If you relied on 1.x turning the plugin off in production, set `'enabled' => false` for that environment.

## 2. Turn on Craft's MCP server

1. Under **Settings → MCP**, enable the server.
2. **Pick endpoint paths that do not start with `mcp` inside the control panel**, for example `/admin/ai` and `/admin/ai/auth`. If the control panel runs on the root of its own domain (no `cpTrigger`), use `/ai` and `/ai/auth`. The plugin's handle is `mcp`, so Craft serves a control panel path starting with `mcp` as this plugin's page, and clients get a login screen instead of the server.
3. Generate Craft's OAuth signing keys once per environment: `php craft oauth/generate-keys`. Alternatively, set the `privateKey` and `publicKey` OAuth config settings. Without them, no client can finish connecting.
4. Run `php craft mcp/check`. It reports each of the above, and exits non-zero if something is wrong.

Craft's MCP settings live in project config, so steps 1 and 2 travel with your deploy. Step 3 does not: the keys stay in each environment's `storage/`, or in the config settings.

## 3. Reconnect your clients

- **Claude (web, Desktop, mobile), ChatGPT and other HTTP clients:** remove the old connector, then add a new one with the URL shown under Settings → MCP. Log in to Craft and approve the requested access.
- **Claude Code, Cursor, Codex, Claude Desktop (local):** run `php craft mcp/install` again. The new configs start `php craft mcp/serve --user=…` instead of `bin/mcp-server`.

## 4. Scripts and CI

Static tokens are gone. Run the stdio server as a user instead:

```bash
php craft mcp/serve --user=ci@example.com
```

Permission checks now apply over stdio too, because the server acts as that user.

## 5. Tool names

Every plugin tool now carries a `stimmt_` prefix. Tools that Craft's own server covers were removed. Update prompts, scripts and `disabledTools`:

| 1.x | 2.0 |
|---|---|
| `list_entries`, `get_entry` | Craft: `elements_list`, `elements_get` (type `entries`) |
| `create_entry`, `update_entry` | Craft: `elements_create`, `elements_update`. Use `stimmt_refs_resolve` to turn natural keys into the ids they take |
| `describe_entry_schema` | Craft: `elements_schema`, `elements_field_schema` |
| `count_entries` | `stimmt_entries_count` |
| `list_drafts`, `list_revisions` | Craft: `drafts_list`, `revisions_list` |
| `publish_entry` | `stimmt_drafts_publish` (Pro). Craft's `drafts_apply` does not validate the draft as live content |
| `delete_entry`, `duplicate_entry` | Craft: `elements_delete`, `elements_duplicate` |
| `copy_entry_to_site` | `stimmt_entries_copy_to_site` (Pro) |
| `create_nested_entry`, `move_nested_entry` | `stimmt_nested_entries_create`, `stimmt_nested_entries_move` (Pro) |
| `list_assets`, `get_asset` | Craft: `elements_list`, `elements_get` (type `assets`) |
| `upload_asset` | Craft: `assets_upload_url`, `assets_create` |
| `update_asset`, `delete_asset` | Craft: `elements_update`, `elements_delete` |
| `list_volumes`, `list_asset_folders` | Craft: `configuration_list` (type `volumes`), `asset_folders_list` |
| `list_users`, `create_user`, `update_user`, `set_user_status` | Craft: `elements_*` with type `users`, `user_permissions_*` |
| `list_categories`, `list_globals` | `stimmt_categories_list`, `stimmt_globals_list` |
| `list_sections`, `list_fields`, `list_sites`, `get_site`, `list_site_groups` | Craft: `configuration_list`, `configuration_get` |
| `list_plugins`, `get_system_info` | Craft: `plugins_list`, `info_get` |
| `clear_caches`, `get_queue_jobs` | Craft: `caches_clear`, `queue_status` |
| `get_project_config_diff` | Craft: `project_config_diff` |
| `get_database_schema`, `get_database_info`, `get_table_counts`, `run_query`, `explain_query` | `stimmt_db_schema`, `stimmt_db_info`, `stimmt_db_table_counts`, `stimmt_db_query`, `stimmt_db_explain` |
| `read_logs`, `get_last_error`, `get_deprecations` | `stimmt_logs_read`, `stimmt_errors_last`, `stimmt_deprecations_list` |
| `get_config`, `get_environment`, `list_routes`, `list_console_commands`, `list_event_handlers` | `stimmt_config_get`, `stimmt_environment_get`, `stimmt_routes_list`, `stimmt_console_commands_list`, `stimmt_event_handlers_list` |
| `list_graphql_schemas`, `get_graphql_schema`, `query_graphql`, `execute_graphql`, `list_graphql_tokens` | `stimmt_graphql_schemas_list`, `stimmt_graphql_schema_get`, `stimmt_graphql_query`, `stimmt_graphql_execute`, `stimmt_graphql_tokens_list` |
| `list_backups`, `create_backup` | `stimmt_backups_list`, `stimmt_backups_create` |
| `tinker` | `stimmt_tinker` |
| `list_products`, `get_product`, `list_orders`, `get_order`, `list_order_statuses`, `list_product_types` | `stimmt_commerce_products_list`, `stimmt_commerce_product_get`, `stimmt_commerce_orders_list`, `stimmt_commerce_order_get`, `stimmt_commerce_order_statuses_list`, `stimmt_commerce_product_types_list` |
| `get_mcp_info`, `list_mcp_tools`, `reload_mcp` | removed. Craft: `info_get`, and `php craft mcp/check` for the plugin's side |
| prompts, `craft://` resources | removed. Craft serves its own resources, and the plugin serves `stimmt://guides/tools` |

The developer tools (database, logs, config, debugging, GraphQL, backups, tinker) are now for admin users only, and need the admin scope over HTTP.

## For plugin developers

The `registerTools`, `registerPrompts` and `registerResources` events, `McpToolMeta` and `ConditionalProvider` are gone. Register your capability classes with Craft directly: see [docs/extending.md](docs/extending.md). `Mcp::EVENT_REGISTER_FIELD_TRANSLATORS` remains, for the natural-key payloads that the Pro write tools accept.
