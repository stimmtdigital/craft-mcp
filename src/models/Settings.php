<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use craft\base\Model;
use Override;

/**
 * The plugin's settings, read from `config/craft-mcp.php`.
 *
 * `config/mcp.php` belongs to Craft's own MCP server since Craft 5.12
 * (`instructions`, `filters`), and Craft refuses unknown keys there, so the
 * plugin keeps its settings in a file of its own. Loading, environment
 * resolution and the production defaults live in the plugin class.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Settings extends Model {
    /**
     * Whether the plugin adds its tools to Craft's MCP server at all.
     */
    public bool $enabled = true;

    /**
     * Tool names (with their `stimmt_` prefix) to leave out of Craft's MCP
     * server.
     *
     * @var string[]
     */
    public array $disabledTools = [];

    /**
     * Whether tools that run code, write SQL, execute GraphQL mutations or
     * create backups are offered. Forced off in production unless the config
     * file sets it explicitly.
     */
    public bool $enableDangerousTools = true;

    /**
     * The largest response, in bytes, a list-shaped tool returns before it
     * refuses and asks for a narrower request. Keeps one call from flooding
     * the model's context.
     */
    public int $maxResponseBytes = 1048576;

    /**
     * How long a preview link from `stimmt_entries_preview_url` stays valid,
     * in seconds. Null uses Craft's `defaultTokenDuration`.
     */
    public ?int $previewTokenDuration = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    #[Override]
    protected function defineRules(): array {
        return [
            [['enabled', 'enableDangerousTools'], 'boolean'],
            [['maxResponseBytes'], 'integer', 'min' => 1024],
            [['previewTokenDuration'], 'integer', 'min' => 60, 'skipOnEmpty' => true],
            [['disabledTools'], 'each', 'rule' => ['string']],
        ];
    }
}
