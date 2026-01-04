<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use craft\base\Model;
use Override;

/**
 * MCP Plugin Settings.
 *
 * A simple value object - config loading is handled by the Mcp class.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Settings extends Model {
    public bool $enabled = true;

    /** @var string[] */
    public array $disabledTools = [];

    /** @var string[] */
    public array $disabledPrompts = [];

    /** @var string[] */
    public array $disabledResources = [];

    public bool $enableDangerousTools = true;

    /** @var string[] */
    public array $allowedIps = [];

    /**
     * @return array<int, array<int|string, mixed>>
     */
    #[Override]
    public function defineRules(): array {
        return [
            [['enabled', 'enableDangerousTools'], 'boolean'],
            [['disabledTools', 'disabledPrompts', 'disabledResources', 'allowedIps'], 'each', 'rule' => ['string']],
        ];
    }
}
