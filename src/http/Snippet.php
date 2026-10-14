<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use Craft;
use stimmt\craft\Mcp\Mcp;

/**
 * The client-config snippet shown with a new token: single source for the
 * console command and the control panel, so both surfaces print the identical
 * `mcpServers` block and resolve the endpoint URL the same way.
 *
 * WHY Claude Code's `.mcp.json` rather than Claude Desktop's config file: the
 * desktop file starts local servers only, so a remote `url` there was never a
 * documented setup. `.mcp.json` takes `type: http` with a URL and headers, and
 * the Claude apps connect through the OAuth connector instead of a token.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Snippet {
    /**
     * The `.mcp.json` block for Claude Code, exactly as printed by the
     * console command.
     */
    public static function json(string $plaintext, string $url): string {
        return <<<JSON
  {
    "mcpServers": {
      "craft-cms": {
        "type": "http",
        "url": "{$url}",
        "headers": { "Authorization": "Bearer {$plaintext}" }
      }
    }
  }

JSON;
    }

    /**
     * The endpoint URL: httpPublicUrl setting when set, else the primary
     * site's base URL.
     */
    public static function url(): string {
        return self::baseUrl() . '/' . Mcp::settings()->httpPath;
    }

    /**
     * Where Craft answers, without a trailing slash: the httpPublicUrl
     * setting when set, else the primary site's base URL. The OAuth issuer
     * and its well-known documents hang off this too.
     */
    public static function baseUrl(): string {
        $base = Mcp::settings()->httpPublicUrl ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '';

        return rtrim($base, '/');
    }

    /**
     * The config block, or null when showClientConfigSnippet is off (#62).
     * The single gate for every caller (the CP reveal screen, the console
     * command): neither checks the setting itself, so the two surfaces can
     * never disagree on whether the block appears.
     */
    public static function jsonIfEnabled(string $plaintext, string $url): ?string {
        return Mcp::settings()->showClientConfigSnippet ? self::json($plaintext, $url) : null;
    }
}
