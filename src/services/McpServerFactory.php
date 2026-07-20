<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use craft\elements\User;
use Mcp\Capability\Registry;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\enums\ToolAction;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\support\EventDispatcher;
use stimmt\craft\Mcp\support\FileLogger;
use stimmt\craft\Mcp\support\Psr11ContainerAdapter;
use stimmt\craft\Mcp\support\Psr16CacheAdapter;

/**
 * Factory for creating MCP Server instances.
 *
 * Follows DIP: depends on abstractions (ContainerInterface, registries via McpRegistry facade).
 * Follows SRP: sole responsibility is building properly configured Server instances.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class McpServerFactory {
    public function __construct(private readonly ?ContainerInterface $container = new Psr11ContainerAdapter(), private readonly ?LoggerInterface $logger = null) {
    }

    /**
     * Create a configured MCP Server instance. The global tool settings
     * (disabledTools, enableDangerousTools, method conditions) are enforced
     * on every transport; a non-null $scope narrows the registry further for
     * the HTTP path. A session store overrides the SDK in-memory default.
     */
    public function create(?Scope $scope = null, ?SessionStoreInterface $sessionStore = null): Server {
        $logger = $this->logger ?? new NullLogger();
        // Shared between the Registry and the Builder: capabilities (e.g.
        // toolsListChanged) are only advertised as true when the Builder's
        // own eventDispatcher is set, and Registry mutations only actually
        // fire events through the instance it was constructed with.
        $eventDispatcher = new EventDispatcher();
        $registry = new Registry($eventDispatcher, $logger);

        $builder = Server::builder()
            ->setServerInfo(
                name: 'Craft CMS MCP Server',
                version: Mcp::getInstance()?->getVersion() ?? '1.0.0',
            )
            ->setInstructions($this->getInstructions($scope))
            ->setDiscovery(
                basePath: dirname(__DIR__),
                scanDirs: ['tools', 'prompts', 'resources'],
                excludeDirs: ['vendor', 'support', 'services', 'events', 'models', 'enums', 'attributes', 'completions', 'contracts', 'elements', 'http', 'records', 'migrations', 'controllers', 'console', 'installer'],
                cache: new Psr16CacheAdapter(Craft::$app->getCache()),
            )
            ->setContainer($this->container)
            ->setRegistry($registry)
            ->setEventDispatcher($eventDispatcher)
            ->setPaginationLimit(Mcp::settings()->paginationLimit);

        if ($sessionStore !== null) {
            $builder->setSession(sessionStore: $sessionStore);
        }

        // Add custom logger if provided (writes to separate file, not Craft logs)
        if ($this->logger !== null) {
            $builder->setLogger($this->logger);
        }

        $this->registerExternalElements($builder);

        $server = $builder->build();
        $this->filterTools($registry, $scope);

        return $server;
    }

    /**
     * Create a StdioTransport for the server.
     */
    public function createTransport(): StdioTransport {
        return new StdioTransport();
    }

    /**
     * HTTP transport for one request, with the SDK's default protections and
     * the current host added to the DNS-rebinding allowlist (the default list
     * is localhost-only, which would reject every staging domain). Admitting
     * the request's own host makes that middleware permissive by design here;
     * the real gate is bearer auth in the controller plus Craft's own
     * trusted-host validation upstream.
     */
    public function createHttpTransport(ServerRequestInterface $request, string $hostName): StreamableHttpTransport {
        $middleware = [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(allowedHosts: ['localhost', '127.0.0.1', '[::1]', strtolower($hostName)]),
            new ProtocolVersionMiddleware(),
        ];

        return new StreamableHttpTransport($request, logger: $this->logger, middleware: $middleware);
    }

    /**
     * Create a file logger that writes to storage/logs/mcp-server.log.
     * This is separate from Craft's logging system.
     */
    public static function createFileLogger(?string $logPath = null, string $logLevel = 'error'): LoggerInterface {
        if ($logPath === null) {
            $logPath = Craft::getAlias('@storage/logs/mcp-server.log');
        }

        return new FileLogger($logPath, $logLevel);
    }

    /**
     * Unregister every tool the global settings disallow (disabledTools,
     * enableDangerousTools, method conditions) and, when a scope is given,
     * everything outside it. Runs against the informational ToolRegistry, so
     * external event-registered tools are covered too.
     */
    private function filterTools(Registry $registry, ?Scope $scope): void {
        $showLocked = Mcp::settings()->showLockedProTools;
        $current = Mcp::currentEdition();

        foreach (Mcp::getToolRegistry()->getDefinitions() as $definition) {
            $otherwiseAllowed = Mcp::isToolEnabled($definition->name)
                && ($scope === null || $scope->allows($definition->category, $definition->dangerous))
                && $this->privilegedAllowed($definition, $scope);
            $editionAllows = $current->atLeast($definition->requiredEdition);

            $action = self::decide($otherwiseAllowed, $editionAllows, $showLocked);

            if ($action === ToolAction::Hide || $action === ToolAction::Lock) {
                // Lock is upgraded to a visible stub in Task 8; until then it is
                // treated as Hide so a Pro tool is never left callable.
                $registry->unregisterTool($definition->name);
            }
        }
    }

    /**
     * Decide what to do with one tool given whether it is otherwise allowed
     * (enabled, in scope, privilege-ok), whether the active edition permits it,
     * and whether the site owner keeps locked tools visible.
     */
    private static function decide(bool $otherwiseAllowed, bool $editionAllows, bool $showLocked): ToolAction {
        if (!$otherwiseAllowed) {
            return ToolAction::Hide;
        }

        if ($editionAllows) {
            return ToolAction::Keep;
        }

        return $showLocked ? ToolAction::Lock : ToolAction::Hide;
    }

    /**
     * Privileged (install-introspection) tools are hidden from read-scoped
     * tokens whose user is not an admin, unless the site owner opened the tool
     * in config. Full scope and stdio are never gated on this axis.
     */
    private function privilegedAllowed(ToolDefinition $definition, ?Scope $scope): bool {
        if (!$definition->privileged || $scope === null || $scope === Scope::Full) {
            return true;
        }

        $identity = Craft::$app->getUser()->getIdentity();
        if ($identity instanceof User && $identity->admin) {
            return true;
        }

        return in_array($definition->name, Mcp::settings()->scopedTokenPrivilegedTools, true);
    }

    private function getInstructions(?Scope $scope = null): string {
        return $this->baseInstructions() . $this->scopeNote($scope);
    }

    /**
     * Per-connection scope note so the instructions are truthful for the
     * token at hand; stdio (null scope) carries no note.
     */
    private function scopeNote(?Scope $scope): string {
        return match ($scope) {
            null => '',
            Scope::ReadOnly => "\n\n## This Connection\n\nThis connection is READ-ONLY: no write, publish, or destructive tools are available, and the Writing Content section above does not apply here. Focus on browsing and inspection (`list_*`, `get_*`, `describe_entry_schema`).",
            Scope::Content => "\n\n## This Connection\n\nThis connection has CONTENT scope: read everything, and write entries through the draft-first flow above (create, update, publish, delete, duplicate, copy to site). Code execution, raw SQL, GraphQL mutation, cache, and backup tools are not available.",
            Scope::Full => "\n\n## This Connection\n\nThis connection has FULL scope: every tool the server exposes is available, including code execution and database tools. Prefer draft-mode writes and read-only queries unless the task requires more.",
        };
    }

    private function baseInstructions(): string {
        return <<<'INSTRUCTIONS'
This MCP server provides access to a Craft CMS installation.

## Available Capabilities

**Tools**: Query and manage entries, assets, users, categories, commerce data
**Resources**: Read configuration, schema information, system state
**Prompts**: Generate content, analyze structure, create entries

## Writing Content (read this before create_entry/update_entry)

1. Call `describe_entry_schema` for the section first; pass `example` (an entry id or slug) to get a real entry as a golden fixture. Every field carries an `input` shape describing the exact payload it accepts.
2. The payload format is symmetric: what `get_entry` returns is exactly what `create_entry`/`update_entry` accept. Read one, tweak it, write it back.
3. Use natural keys, never numeric ids: relations are `{"section": "...", "slug": "..."}`, assets `{"volume": "...", "filename": "..."}`, categories/tags `{"group": "...", "slug": "..."}`, users `{"username": "..."}`. Matrix blocks are keyed objects (`new1`, `new2`, ...) with the entry-type handle as `type`.
4. Writes land as DRAFTS by default: the response carries `draftElementId` and a `cpEditUrl` deep link for human review; `publish_entry` makes them live. Nothing touches live content until published.
5. Always read the `warnings` list on write responses: unresolvable natural keys become warnings, never guesses or silent drops. Validation failures return per-field errors.
6. Multi-site installs: pass the `site` handle parameter; `copy_entry_to_site` moves content between sites.

The full contract lives in the `craft://guides/content-writing` resource.

## Choosing Tools

Prefer the most specific tool and escalate only when none fits:

1. Content questions: `list_entries` (field filters, `relatedTo`, `search`, date ranges, `fields` projection), `get_entry`, `count_entries` for totals and per-value breakdowns, `list_drafts` for the review queue, `list_revisions` for an entry's history, `describe_entry_schema` for payload shapes.
2. Other element types and nested shapes: `query_graphql` reads anything Craft's GraphQL schema exposes (assets, categories, users, plugin types) with exactly the response shape you ask for.
3. Database structure: `get_database_schema` and `get_table_counts`, never hand-written information_schema queries.
4. `run_query` covers what no structured tool does: custom plugin tables and aggregate SQL (SELECT only).
5. `tinker` is the last resort, for logic no tool can express (cross-entry computation, service calls). Keep analysis code read-only; write content through the entry tools so drafts, validation, and warnings stay in play.
6. Tools marked with a destructive annotation modify data or execute code; prefer draft-mode writes and review flows.
INSTRUCTIONS;
    }

    private function registerExternalElements(Builder $builder): void {
        $this->registerExternalTools($builder);
        $this->registerExternalPrompts($builder);
        $this->registerExternalResources($builder);
    }

    private function registerExternalTools(Builder $builder): void {
        foreach (McpRegistry::tools()->getExternalToolDefinitions() as $def) {
            $builder->addTool(
                handler: [$def->class, $def->method],
                name: $def->name,
                description: $def->description,
            );
        }
    }

    private function registerExternalPrompts(Builder $builder): void {
        foreach (McpRegistry::prompts()->getExternalPromptDefinitions() as $def) {
            $builder->addPrompt(
                handler: [$def->class, $def->method],
                name: $def->name,
                description: $def->description,
            );
        }
    }

    private function registerExternalResources(Builder $builder): void {
        foreach (McpRegistry::resources()->getExternalResourceDefinitions() as $def) {
            $this->registerResource($builder, $def);
        }
    }

    private function registerResource(Builder $builder, ResourceDefinition $def): void {
        if ($def->isTemplate) {
            $builder->addResourceTemplate(
                handler: [$def->class, $def->method],
                uriTemplate: $def->uri,
                name: $def->name,
                description: $def->description,
                mimeType: $def->mimeType,
            );

            return;
        }

        $builder->addResource(
            handler: [$def->class, $def->method],
            uri: $def->uri,
            name: $def->name,
            description: $def->description,
            mimeType: $def->mimeType,
        );
    }
}
