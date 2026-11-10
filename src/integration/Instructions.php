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
            '- Admins also get developer tools: stimmt_db_*, stimmt_logs_read, stimmt_errors_last, stimmt_config_get, stimmt_graphql_*, stimmt_tinker and more. Prefer them over guessing about the install.',
        ];

        $lines = [...$lines, ...self::editionLines($edition)];

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function editionLines(Edition $edition): array {
        if (!$edition->atLeast(Edition::Pro)) {
            return ['- The plugin\'s Pro edition adds validated publishing (stimmt_drafts_publish), copying entries between sites and single Matrix-block tools. ' . Edition::UPGRADE_CTA];
        }

        return [
            '- Publish drafts with stimmt_drafts_publish rather than drafts_apply: it validates the draft as live content first, so a required field cannot go live empty.',
            '- stimmt_entries_copy_to_site copies an entry to another site, and stimmt_nested_entries_create and stimmt_nested_entries_move add or reposition one Matrix block without rewriting the field.',
        ];
    }
}
