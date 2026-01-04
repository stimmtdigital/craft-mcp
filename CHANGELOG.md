# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-01-04

### Added
- 18 new MCP tools expanding the toolset from 32 to 50 tools:
  - Multi-site tools: `list_sites`, `get_site`, `list_site_groups`
  - GraphQL tools: `list_graphql_schemas`, `get_graphql_schema`, `execute_graphql`, `list_graphql_tokens`
  - Backup tools: `list_backups`, `create_backup`
  - Self-awareness tools: `get_mcp_info`, `list_mcp_tools`, `reload_mcp`
  - Commerce tools (Craft Commerce): `list_products`, `get_product`, `list_orders`, `get_order`, `list_order_statuses`, `list_product_types`
- 9 MCP prompts for guided analysis workflows
  - Content analysis: `content_health_analysis`, `content_audit`, `debug_content_issue`
  - Entry management: `create_entry_guide`, `query_entries_guide`, `bulk_entry_operations`
  - Schema exploration: `explore_section_schema`, `field_usage_analysis`, `explore_content_model`
- 12 MCP resources for read-only URI-based data access
  - Schema resources: `craft://schema/sections`, `craft://schema/fields`, `craft://schema/sections/{handle}`, `craft://schema/fields/{handle}`
  - Config resources: `craft://config/general`, `craft://config/routes`, `craft://config/sites`, `craft://config/volumes`, `craft://config/plugins`
  - Content resources: `craft://entries/{section}`, `craft://entries/{section}/{slug}`, `craft://entries/{section}/stats`
- 7 completion providers for intelligent parameter auto-completion
- Extension system for custom prompts via `EVENT_REGISTER_PROMPTS` event
- Extension system for custom resources via `EVENT_REGISTER_RESOURCES` event
- SSH tunnel configuration example for remote server access
- Comprehensive documentation for prompts (`docs/prompts.md`)
- Comprehensive documentation for resources (`docs/resources.md`)
- Extended documentation in `docs/extending.md` covering prompts, resources, and completion providers
- Hot-reload support for newly installed plugins without MCP server restart

### Changed
- Tool count updated from 32 to 50 tools
- Documentation updated to reflect all available features
- Refactored error handling to align with MCP SDK conventions
- Applied Rector code quality improvements
- PSR-12 compliance across all files
- Added unit tests for new tool classes (BackupTools, CommerceTools, GraphqlTools, McpTools, SiteTools)

## [1.0.1] - 2026-01-03

### Fixed
- `clear_caches` tool now uses correct `CraftFileHelper::clearDirectory()` instead of non-existent `Fs::clearDirectory()`
- `read_logs` tool now dynamically finds all log files including date-based logs (e.g., `web-2026-01-03.log`)
- `read_logs` tool regex pattern updated to match Craft CMS log format
- `get_environment` tool removed non-existent `getTranslationsPath()` call

## [1.0.0] - 2026-01-03

### Added
- Initial release
- 32 MCP tools for Craft CMS
- Schema & Structure tools (list_plugins, list_sections, list_fields, list_volumes, list_routes, list_console_commands)
- Content tools (list_entries, get_entry, create_entry, update_entry, list_globals, list_categories, list_users)
- Asset tools (list_assets, get_asset, list_asset_folders)
- System tools (get_system_info, get_config, read_logs, get_last_error, clear_caches)
- Database tools (get_database_info, get_database_schema, get_table_counts, run_query)
- Tinker tool for PHP execution
- Debugging tools (get_queue_jobs, get_project_config_diff, get_deprecations, explain_query, get_environment, list_event_handlers)
- Extension system via `EVENT_REGISTER_TOOLS` event
- Production safety defaults (disabled by default in production)
- Dangerous tools protection (tinker, run_query, create_entry, update_entry, clear_caches)