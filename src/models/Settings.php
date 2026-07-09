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

    public string $logLevel = 'error';

    /**
     * Default save mode for entry writes: 'draft' (reviewable) or 'live'.
     */
    public string $entryWriteMode = 'draft';

    /** Master switch for the HTTP transport. Off by default; enabling it registers the site URL rule. */
    public bool $httpTransport = false;

    /** Endpoint path on the primary site (no leading slash). */
    public string $httpPath = 'mcp';

    /** HTTP session TTL in seconds. */
    public int $httpSessionTtl = 3600;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    #[Override]
    public function defineRules(): array {
        return [
            [['enabled', 'enableDangerousTools', 'httpTransport'], 'boolean'],
            [['disabledTools', 'disabledPrompts', 'disabledResources', 'allowedIps'], 'each', 'rule' => ['string']],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
            [['entryWriteMode'], 'in', 'range' => ['draft', 'live']],
            [['httpPath'], 'required'],
            [['httpPath'], 'match', 'pattern' => '/^[a-z0-9\-\/]+$/i'],
            [['httpSessionTtl'], 'integer', 'min' => 60],
        ];
    }
}
