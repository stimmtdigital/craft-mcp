<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Instructions;

/**
 * What the plugin adds to the instructions Craft's MCP server sends on
 * connect. The edition decides whether the Pro tools are offered as tools to
 * call or as an upgrade, and the two must never be mixed: a Lite client told
 * to call stimmt_drafts_publish calls a tool the Gate has hidden.
 */
describe('Instructions::text()', function () {
    it('names the plugin\'s Lite tools on every edition', function (Edition $edition) {
        expect(Instructions::text($edition))
            ->toContain('stimmt_refs_resolve')
            ->toContain('stimmt_entries_count')
            ->toContain('stimmt_entries_preview_url');
    })->with([[Edition::Lite], [Edition::Pro]]);

    it('offers Pro on Lite, without presenting its tools as available', function () {
        $text = Instructions::text(Edition::Lite);

        expect($text)->toContain('Pro edition adds')
            ->toContain(Edition::UPGRADE_CTA)
            ->and($text)->not->toContain('Publish drafts with stimmt_drafts_publish')
            ->and($text)->not->toContain('stimmt_nested_entries_create and');
    });

    // Craft's own drafts_apply skips the live validation the control panel
    // runs, which is how a required field went live empty (#76).
    it('tells a Pro client to publish with stimmt_drafts_publish rather than drafts_apply', function () {
        $text = Instructions::text(Edition::Pro);

        expect($text)->toContain('stimmt_drafts_publish rather than drafts_apply')
            ->toContain('stimmt_entries_copy_to_site')
            ->toContain('stimmt_nested_entries_move')
            ->and($text)->not->toContain(Edition::UPGRADE_CTA);
    });
});
