<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

use craft\mcp\events\CollectingServerInstructions;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\Mcp;

/**
 * What the plugin adds to the instructions Craft's MCP server sends a client
 * when it connects: which `stimmt_` tools exist, and when to prefer one over
 * Craft's own.
 */
final class Instructions {
    public static function onCollect(CollectingServerInstructions $event): void {
        if (!Mcp::settings()->enabled) {
            return;
        }

        $event->instructions[] = self::text(Mcp::currentEdition());
    }

    public static function text(Edition $edition): string {
        $lines = [
            '## Craft MCP plugin (stimmt_ tools)',
            '- Craft\'s elements_* tools take element ids. stimmt_refs_resolve turns a natural key (entry {"section","slug"}, asset {"volume","filename"}, category or tag {"group","slug"}, user {"username"}, globalSet {"handle"}) into that id.',
            '- stimmt_entries_count counts entries and groups them by status, type, section, author, date bucket or field value in one call.',
            '- stimmt_entries_preview_url gives a person a link to review an entry or draft before it is published.',
            '- Categories and global sets, which Craft\'s tools leave out, are readable through stimmt_categories_list and stimmt_globals_list.',
            '- Admins also get developer tools. When something is wrong with the install, start with stimmt_health, then stimmt_errors_summary (recurring errors) and stimmt_queue_failures; stimmt_logs_read, stimmt_db_*, stimmt_config_get, stimmt_graphql_* and stimmt_tinker go deeper. Prefer them over guessing about the install.',
        ];

        $lines = [...$lines, ...self::editionLines($edition)];

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function editionLines(Edition $edition): array {
        if (!$edition->atLeast(Edition::Pro)) {
            return ['- The plugin\'s Pro edition adds the site-management tools: draft review and validated publishing, content health (translation gaps, broken links, unused assets), a change feed, Retour/SEOmatic/Formie insights, a maintenance report, copying entries between sites and single Matrix-block tools. ' . Edition::UPGRADE_CTA];
        }

        return [
            '- stimmt_drafts_diff shows field by field what a draft changes before it is published; show it to the person reviewing.',
            '- Publish drafts with stimmt_drafts_publish rather than drafts_apply: it validates the draft as live content first, so a required field cannot go live empty.',
            '- Content health: stimmt_entries_untranslated finds translation gaps between sites, stimmt_links_broken finds links to deleted or unpublished content, stimmt_assets_unused finds files nothing refers to (never delete them without asking). stimmt_activity_recent shows who changed what.',
            '- Where Retour, SEOmatic or Formie is installed: stimmt_redirects_404s, stimmt_seo_gaps and stimmt_forms_failures. For a client report over a period, admins use stimmt_maintenance_report and summarise it.',
            '- stimmt_entries_copy_to_site copies an entry to another site, and stimmt_nested_entries_create and stimmt_nested_entries_move add or reposition one Matrix block without rewriting the field.',
        ];
    }
}
