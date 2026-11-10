<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * Masks anything token shaped before it is printed or recorded.
 *
 * WHY at every exit rather than only where a token is expected: the harness
 * holds three live bearer tokens, and stimmt_entries_preview_url answers with a
 * link carrying a live preview token. A failure message, an assertion that
 * renders the value it disagreed with, or a body excerpt from a refused request
 * can each carry one of them without anyone meaning it to. Every string that
 * leaves the harness for a terminal or a baseline goes through here.
 */
final class Redact {
    private const array PATTERNS = [
        // A signed JWT, which is what Craft's personal access tokens are.
        '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/' => '<jwt>',
        '/(Bearer\s+)\S+/i' => '$1<token>',
        // Craft's preview and impersonation tokens travel as a query parameter.
        '/([?&](?:x-craft-preview|x-craft-live-preview|token)=)[^&\s"\'\\\\]+/i' => '$1<token>',
        '/("(?:token|access_token|accessToken)"\s*:\s*")[^"]+/i' => '$1<token>',
    ];

    public static function text(string $text): string {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }
}
