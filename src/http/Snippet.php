<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use Craft;
use stimmt\craft\Mcp\Mcp;

/**
 * The Claude Desktop client-config snippet: single source for the console
 * command and the control panel, so both surfaces print the identical
 * `mcpServers` block and resolve the endpoint URL the same way.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Snippet {
    /**
     * The Claude Desktop config block (claude_desktop_config.json), exactly
     * as printed by the console command.
     */
    public static function json(string $plaintext, string $url): string {
        return <<<JSON
  {
    "mcpServers": {
      "craft-cms": {
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
        $settings = Mcp::settings();
        $base = $settings->httpPublicUrl ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '';

        return rtrim($base, '/') . '/' . $settings->httpPath;
    }
}
