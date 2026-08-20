<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\policy\Gate;
use stimmt\craft\Mcp\psr\Cache;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\Build;
use stimmt\craft\Mcp\support\PluginReloader;
use stimmt\craft\Mcp\support\Response;
use yii\caching\TagDependency;

/**
 * Self-awareness tools for the MCP plugin.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class McpTools {
    private readonly Gate $gate;

    /**
     * The Gate is the connection: it knows the scope, and it is what the server
     * itself filtered the registry with. Reading it here rather than
     * re-deriving means these tools cannot report a different answer from the
     * one tools/list acts on. An unscoped default keeps the class
     * constructible where no connection was served, such as a unit test.
     */
    public function __construct(?Gate $gate = null) {
        $this->gate = $gate ?? new Gate();
    }

    /**
     * The edition half of a listing row: what the tool needs, and whether this
     * install is below it.
     *
     * Separate from `available` on purpose. That answers "can I call this",
     * which a scope or a permission can also decide; this answers the narrower
     * question an upgrade prompt actually needs, and stays true regardless of
     * which connection is asking.
     *
     * @return array{requiredEdition: string, locked: bool}
     */
    public static function editionFields(ToolDefinition $definition): array {
        return [
            'requiredEdition' => $definition->requiredEdition->value,
            'locked' => !Mcp::currentEdition()->atLeast($definition->requiredEdition),
        ];
    }

    /**
     * Get information about the MCP plugin itself.
     */
    #[McpTool(
        name: 'get_mcp_info',
        title: 'MCP server status',
        description: 'Get information about the Craft MCP plugin including version, status, and configuration',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CORE)]
    public function getMcpInfo(
        #[Schema(description: 'Include the parts that cost something to produce or to read: the reason each unavailable tool is unavailable, the text of any registration errors, and install facts. Off by default because this tool is often the first call of a session.')]
        bool $detail = false,
        ?RequestContext $context = null,
    ): array {
        $plugin = Mcp::getInstance();
        $settings = Mcp::settings();
        $registry = Mcp::getToolRegistry();

        $summary = $registry->getSummary();
        $admitted = $this->admitted();

        return [
            'name' => $plugin !== null ? $plugin->name : 'Craft MCP',
            'handle' => $plugin !== null ? $plugin->handle : 'mcp',
            'version' => $plugin !== null ? $plugin->version : 'unknown',
            'build' => Build::reference(),
            // Which of the two answers above was observed from the code on disk
            // rather than taken from composer's record of what it installed.
            // A path or symlink install runs code composer never re-read, so
            // the recorded version can name a branch that is no longer checked
            // out, and did.
            'buildSource' => Build::source(),
            // Only on a working copy, and only then because the version above
            // can name the branch composer installed rather than the one on disk.
            'branch' => Build::branch(),
            'schemaVersion' => $plugin !== null ? $plugin->schemaVersion : 'unknown',
            'status' => [
                'enabled' => $settings->enabled,
                'dangerousToolsEnabled' => $settings->enableDangerousTools,
                'environment' => Craft::$app->env ?? getenv('CRAFT_ENVIRONMENT') ?: 'production',
            ],
            'connection' => $this->connection(),
            'tools' => [
                'total' => $summary['total'],
                // What this connection may actually call. Equal to total on an
                // unscoped connection; the gap is the point of reporting both.
                'available' => count($admitted['allowed']),
                'unavailable' => count($admitted['denied']),
                'bySource' => $summary['by_source'],
                'byCategory' => $summary['by_category'],
                'dangerous' => $summary['dangerous'],
            ] + ($detail ? ['unavailableTools' => $admitted['denied']] : []),
            'health' => $this->health($detail),
            'configuration' => [
                'disabledTools' => $settings->disabledTools,
            ],
        ];
    }

    /**
     * What this connection is, in the terms it was granted.
     *
     * The transport is read from the request rather than passed in, because a
     * console request is what stdio actually runs as and a web request is what
     * the HTTP endpoint actually runs as. Observing it cannot fall out of step
     * with the truth the way a flag threaded through three constructors can.
     *
     * @return array<string, mixed>
     */
    private function connection(): array {
        // getIdentity() answers with the interface, and only a Craft user has a
        // username and an admin flag to report.
        $identity = Craft::$app->getUser()->getIdentity();
        $user = $identity instanceof User ? $identity : null;

        return [
            'transport' => Craft::$app->getRequest()->getIsConsoleRequest() ? 'stdio' : 'http',
            // Null on stdio, which carries no token and is therefore unscoped.
            'scope' => $this->gate->scope?->value,
            'user' => $user?->username,
            // Null rather than false when there is no user at all: stdio is
            // not a non-admin, it is unauthenticated and unrestricted, and
            // false would read as a limit that is not there.
            'admin' => $user?->admin,
            // The operative fact, and the one worth acting on: whether this
            // connection may read install internals.
            'privileged' => Authorization::isPrivileged(),
        ];
    }

    /**
     * Every registered tool sorted into what this connection may call and what
     * it may not, each refusal carrying the Gate's own reason.
     *
     * @return array{allowed: list<string>, denied: list<array{name: string, reason: ?string}>}
     */
    private function admitted(): array {
        $allowed = [];
        $denied = [];

        foreach (Mcp::getToolRegistry()->getDefinitions() as $definition) {
            $decision = $this->gate->admitsTool($definition);

            if ($decision->allowed) {
                $allowed[] = $definition->name;

                continue;
            }

            $denied[] = ['name' => $definition->name, 'reason' => $decision->reason];
        }

        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /**
     * What is wrong right now, if anything.
     *
     * The error TEXT is privileged: registration failures name classes and
     * paths on the install. A connection that may not read install internals
     * is told the count and told why it cannot have the rest, rather than
     * being handed a silently shorter answer.
     *
     * @return array<string, mixed>
     */
    private function health(bool $detail): array {
        $errors = Mcp::getToolRegistry()->getErrors();

        $health = [
            'registrationErrors' => count($errors),
            'ok' => $errors === [],
        ];

        if (!$detail) {
            return $health;
        }

        if (!Authorization::isPrivileged()) {
            return $health + ['detail' => 'withheld: reading install internals needs a full-scope or admin connection'];
        }

        return $health + [
            'errors' => array_values($errors),
            'craftVersion' => Craft::$app->getVersion(),
            'phpVersion' => PHP_VERSION,
        ];
    }

    /**
     * List all available MCP tools with their descriptions.
     */
    #[McpTool(
        name: 'list_mcp_tools',
        title: 'MCP tool inventory',
        description: 'List all available MCP tools with their names, descriptions, and enabled status',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CORE)]
    public function listMcpTools(?RequestContext $context = null): array {
        $registry = Mcp::getToolRegistry();
        $definitions = $registry->getDefinitions();

        $tools = [];
        foreach ($definitions as $definition) {
            $decision = $this->gate->admitsTool($definition);

            $tools[] = [
                'name' => $definition->name,
                'description' => $definition->description,
                'source' => $definition->source,
                'category' => $definition->category,
                'dangerous' => $definition->dangerous,
                // Install-introspection reads are hidden from non-admin
                // readonly/content connections, so a listed privileged
                // tool can still be refused; say so rather than letting
                // the listing imply every row is callable.
                'privileged' => $definition->privileged,
                // Asked of the Gate, not of the settings. Settings are one of
                // three reasons a tool may be unavailable, so a settings-only
                // check called 42 callable tools 55 on a readonly connection
                // and marked the 13 it could not call enabled.
                'available' => $decision->allowed,
                'unavailableBecause' => $decision->reason,
                ...self::editionFields($definition),
            ];
        }

        // Sort by source, then category, then name
        usort($tools, function (array $a, array $b): int {
            $sourceCompare = strcmp($a['source'], $b['source']);
            if ($sourceCompare !== 0) {
                return $sourceCompare;
            }

            $categoryCompare = strcmp($a['category'], $b['category']);
            if ($categoryCompare !== 0) {
                return $categoryCompare;
            }

            return strcmp($a['name'], $b['name']);
        });

        // Group counts
        $bySource = [];
        $byCategory = [];
        foreach ($tools as $tool) {
            $bySource[$tool['source']] = ($bySource[$tool['source']] ?? 0) + 1;
            $byCategory[$tool['category']] = ($byCategory[$tool['category']] ?? 0) + 1;
        }

        return [
            'count' => count($tools),
            // The number that matters on a scoped connection, and the one whose
            // absence let the drift hide: count is everything registered,
            // available is what this caller can actually invoke.
            'available' => count(array_filter($tools, static fn (array $tool): bool => $tool['available'])),
            'bySource' => $bySource,
            'byCategory' => $byCategory,
            'tools' => $tools,
        ];
    }

    /**
     * Reload MCP to detect newly installed plugins.
     *
     * This performs a soft reload that can detect newly installed Craft plugins.
     * For code changes in existing plugins, send SIGHUP to the MCP server process.
     */
    #[McpTool(
        name: 'reload_mcp',
        title: 'Reload the MCP server',
        description: 'Reload MCP to detect newly installed plugins. Note: Code changes require sending SIGHUP to the MCP server process.',
        // Without these the spec's conservative defaults apply, so a client
        // prompts for confirmation before a cache refresh that changes no data.
        annotations: new ToolAnnotations(destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CORE)]
    public function reloadMcp(?RequestContext $context = null): array {
        // 1. Reload Composer classmap (detects new plugin classes)
        PluginReloader::reloadComposerClassmap();

        // 2. Refresh Craft's composer plugin info cache (re-reads plugins.php)
        $refreshResult = PluginReloader::refreshComposerPluginInfo();

        // 3. Reset project config to re-read from YAML
        PluginReloader::resetProjectConfig();

        // 4. Reset Plugins service internal caches
        PluginReloader::resetPluginsService();

        // 5. Reload Craft plugins
        Craft::$app->getPlugins()->loadPlugins();

        // 6. Invalidate the cached attribute discovery so it rescans
        TagDependency::invalidate(Craft::$app->getCache(), Cache::TAG);

        // 7. Reset tool registry to re-collect tools
        Mcp::resetToolRegistry();

        $summary = Mcp::getToolRegistry()->getSummary();

        // 8. The connected client's own tool list may now be stale;
        // push the real notification instead of leaving it to guess.
        $context?->getClientGateway()->notify(new ToolListChangedNotification());

        return Response::success([
            'message' => 'MCP plugin state reloaded',
            'pluginsDiscovered' => $refreshResult['plugins'],
            'tools' => $summary,
            'hint' => 'For code changes in existing plugins, send SIGHUP to the MCP server process: kill -HUP $(pgrep -f "mcp-server")',
        ]);
    }
}
