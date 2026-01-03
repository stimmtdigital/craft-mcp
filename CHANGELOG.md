# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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