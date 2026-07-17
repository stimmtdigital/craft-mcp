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

    /**
     * Tool names opened for non-admin readonly/content HTTP tokens despite
     * being privileged install-introspection reads (logs, config, database
     * structure/contents, environment). Empty by default: secure by default,
     * the site owner opts specific tools in.
     *
     * @var string[]
     */
    public array $scopedTokenPrivilegedTools = [];

    /** @var string[] */
    public array $allowedIps = [];

    public string $logLevel = 'error';

    /** Page size for MCP list endpoints (tools/prompts/resources list calls). */
    public int $paginationLimit = 50;

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
     * Base URL clients should reach the endpoint on, e.g. 'https://cms.example.com'.
     * Null derives it from the primary site, which is wrong on headless
     * deployments where Craft answers on a different domain than the site.
     */
    public ?string $httpPublicUrl = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    #[Override]
    public function defineRules(): array {
        return [
            [['enabled', 'enableDangerousTools', 'httpTransport'], 'boolean'],
            [['disabledTools', 'disabledPrompts', 'disabledResources', 'allowedIps', 'scopedTokenPrivilegedTools'], 'each', 'rule' => ['string']],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
            [['paginationLimit'], 'integer', 'min' => 1],
            [['entryWriteMode'], 'in', 'range' => ['draft', 'live']],
            [['httpPath'], 'required'],
            [['httpPath'], 'match', 'pattern' => '/^[a-z0-9\-\/]+$/i'],
            [['httpSessionTtl'], 'integer', 'min' => 60],
            [['httpPublicUrl'], 'url', 'skipOnEmpty' => true],
        ];
    }
}
