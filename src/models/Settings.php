<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use craft\base\Model;
use Override;
use stimmt\craft\Mcp\http\Scope;

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
     * Show Pro-locked tools on a non-Pro install as visible-but-locked (their
     * description marked, their handler returning an upgrade message) instead
     * of hiding them entirely. Off by default: quiet and uncluttered.
     */
    public bool $showLockedProTools = false;

    /**
     * Tool names opened for non-admin readonly/content HTTP tokens despite
     * being privileged install-introspection reads (logs, config, database
     * structure/contents, environment). Empty by default: secure by default,
     * the site owner opts specific tools in.
     *
     * @var string[]
     */
    public array $scopedTokenPrivilegedTools = [];

    /**
     * Scopes that may not be minted on this install (e.g. ['full'] in
     * production), no matter who is asking, admins included: a deliberate
     * per-environment guardrail rather than a Craft permission, since
     * permissions bend to whoever happens to hold them and this must not.
     * Existing tokens of a disabled scope keep working and can still be
     * regenerated; this only blocks minting new ones. Case-sensitive: each
     * entry must exactly match a Scope enum value ('readonly', 'content',
     * or 'full'), see stimmt\craft\Mcp\http\Scope.
     *
     * @var string[]
     */
    public array $disabledScopes = [];

    /** @var string[] */
    public array $allowedIps = [];

    public string $logLevel = 'error';

    /**
     * Page size for MCP list endpoints (tools/prompts/resources list calls).
     * 100 covers every tool the plugin registers in a single page, so clients
     * that ignore nextCursor still see the full list.
     */
    public int $paginationLimit = 100;

    /**
     * Largest single tool result to send, in bytes. 0 disables the check.
     *
     * Deliberately generous. Measured against this install, a `list_entries` at
     * the default page size is about 118 KB and `describe_entry_schema` about
     * 35 KB, so anything tight enough to prevent the transport deadlock would
     * refuse ordinary calls. This catches the pathological end only, where a
     * result nothing downstream can read is better refused with a sentence the
     * caller can act on.
     */
    public int $maxResponseBytes = 1048576;

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
     * Session storage for the HTTP transport. Null uses the built-in
     * database-backed store. Set a class name implementing
     * Mcp\Server\Session\SessionStoreInterface, or a callable returning one,
     * to supply a custom store (for example Redis).
     */
    public mixed $httpSessionStore = null;

    /**
     * Base URL clients should reach the endpoint on, e.g. 'https://cms.example.com'.
     * Null derives it from the primary site, which is wrong on headless
     * deployments where Craft answers on a different domain than the site.
     */
    public ?string $httpPublicUrl = null;

    /**
     * Install-owner text appended to the server instructions, on every
     * transport, absolutely last (after every other note the plugin
     * computes). Empty by default, so it costs nothing on installs that
     * don't set it.
     */
    public string $additionalInstructions = '';

    /**
     * Whether the token-reveal screen (My Account -> MCP Tokens) shows the
     * ready-to-paste Claude Desktop config block alongside the new token.
     * True by default, matching existing behavior. Installs that provision
     * MCP clients their own way (a custom prompt and skill, a different
     * client entirely) can turn this off to leave just the token and its
     * copy/warning UI.
     */
    public bool $showClientConfigSnippet = true;

    /**
     * Whether human-readable tool output (`output=text`) carries ANSI colour.
     * False by default: the usual consumer is a model, and escape sequences
     * reach it as literal `[2m` noise that costs tokens and means nothing.
     * Turn it on when a person reads this server's output in a terminal.
     */
    public bool $colorOutput = false;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    #[Override]
    public function defineRules(): array {
        return [
            [['enabled', 'enableDangerousTools', 'httpTransport', 'showLockedProTools', 'showClientConfigSnippet', 'colorOutput'], 'boolean'],
            [['disabledTools', 'disabledPrompts', 'disabledResources', 'allowedIps', 'scopedTokenPrivilegedTools'], 'each', 'rule' => ['string']],
            [['disabledScopes'], 'each', 'rule' => ['in', 'range' => array_column(Scope::cases(), 'value')]],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
            [['paginationLimit'], 'integer', 'min' => 1],
            [['entryWriteMode'], 'in', 'range' => ['draft', 'live']],
            [['httpPath'], 'required'],
            [['httpPath'], 'match', 'pattern' => '/^[a-z0-9\-\/]+$/i'],
            [['httpSessionTtl'], 'integer', 'min' => 60],
            [['httpPublicUrl'], 'url', 'skipOnEmpty' => true],
            [['additionalInstructions'], 'string'],
        ];
    }
}
