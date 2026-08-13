<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use craft\elements\User;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
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
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\support\EventDispatcher;
use stimmt\craft\Mcp\support\FileLogger;
use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Presenter;
use stimmt\craft\Mcp\support\Psr11ContainerAdapter;
use stimmt\craft\Mcp\support\Psr16CacheAdapter;
use stimmt\craft\Mcp\support\Renderer;

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
            ->setReferenceHandler($this->presenter())
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
        $this->filterPrompts($registry);
        $this->filterResources($registry);

        return $server;
    }

    /**
     * The output layer, decorating the SDK's own reference handler so every
     * tool call passes through it: one payload on the wire instead of two, and
     * central text rendering for tools that declare the output parameter.
     * Built here rather than in the Builder default so the container stays the
     * one this factory already configures.
     */
    private function presenter(): Presenter {
        return new Presenter(
            new ReferenceHandler($this->container),
            new Renderer(Palette::fromSettings()),
        );
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
        $definitions = Mcp::getToolRegistry()->getDefinitions();

        foreach ($definitions as $definition) {
            $allowed = Mcp::isToolEnabled($definition->name)
                && ($scope === null || $scope->allows($definition->category, $definition->dangerous))
                && $this->privilegedAllowed($definition, $scope);

            if (!$allowed) {
                $registry->unregisterTool($definition->name);
            }
        }

        // SDK attribute discovery (setDiscovery() in create()) registers every
        // #[McpTool] method it finds unconditionally, including
        // ConditionalToolProvider classes whose isAvailable() is false, which
        // the informational registry above deliberately omits. Anything the
        // SDK registered that has no definition here has not passed any of
        // the checks in the loop above, so deny it by default rather than
        // leaving it live and ungoverned.
        foreach (array_keys($registry->getTools()->references) as $name) {
            if (!isset($definitions[$name])) {
                $registry->unregisterTool($name);
            }
        }
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

    /**
     * Unregister every prompt disabledPrompts disallows and, mirroring
     * filterTools()'s deny-by-default sweep, any SDK-registered prompt with
     * no informational definition. Attribute discovery registers every
     * #[McpPrompt] method it finds unconditionally, including ones the
     * informational PromptRegistry omits, so an undefined prompt has not
     * passed the check above and is denied by default rather than left live.
     * Prompts carry no scope semantics; that stays a tool-only axis.
     */
    private function filterPrompts(Registry $registry): void {
        $definitions = Mcp::getPromptRegistry()->getDefinitions();

        foreach ($definitions as $definition) {
            if (!Mcp::isPromptEnabled($definition->name)) {
                $registry->unregisterPrompt($definition->name);
            }
        }

        foreach (array_keys($registry->getPrompts()->references) as $name) {
            if (!isset($definitions[$name])) {
                $registry->unregisterPrompt($name);
            }
        }
    }

    /**
     * Same enforcement as filterPrompts(), for resources. Static resources
     * and resource templates are separate SDK collections (keyed by URI and
     * uriTemplate respectively), so both are checked against
     * disabledResources and swept for deny-by-default independently.
     */
    private function filterResources(Registry $registry): void {
        $definitions = Mcp::getResourceRegistry()->getDefinitions();

        foreach ($definitions as $definition) {
            if (Mcp::isResourceEnabled($definition->uri)) {
                continue;
            }

            $definition->isTemplate
                ? $registry->unregisterResourceTemplate($definition->uri)
                : $registry->unregisterResource($definition->uri);
        }

        $this->sweepResources($registry, $definitions);
    }

    /**
     * Deny-by-default sweep for both SDK resource collections. The
     * informational ResourceRegistry keys template definitions by name (see
     * RegisterResourcesEvent), so definitions are re-indexed by URI here
     * rather than matched against their array keys directly.
     *
     * @param array<string, ResourceDefinition> $definitions
     */
    private function sweepResources(Registry $registry, array $definitions): void {
        $staticUris = [];
        $templateUris = [];
        foreach ($definitions as $definition) {
            if ($definition->isTemplate) {
                $templateUris[$definition->uri] = true;

                continue;
            }

            $staticUris[$definition->uri] = true;
        }

        foreach (array_keys($registry->getResources()->references) as $uri) {
            if (!isset($staticUris[$uri])) {
                $registry->unregisterResource($uri);
            }
        }

        foreach (array_keys($registry->getResourceTemplates()->references) as $uriTemplate) {
            if (!isset($templateUris[$uriTemplate])) {
                $registry->unregisterResourceTemplate($uriTemplate);
            }
        }
    }

    /**
     * Tool names the base instructions recommend by name. Disabling one of
     * these via disabledTools makes the recommendation wrong for that
     * connection, so getInstructions() checks the set against
     * Mcp::isToolEnabled() and appends an availabilityNote() when any are
     * unavailable.
     */
    private const array CITED_TOOLS = [
        'describe_entry_schema',
        'get_entry',
        'create_entry',
        'update_entry',
        'publish_entry',
        'copy_entry_to_site',
        'list_entries',
        'count_entries',
        'list_drafts',
        'list_revisions',
        'query_graphql',
        'get_database_schema',
        'get_table_counts',
        'run_query',
        'tinker',
    ];

    private function getInstructions(?Scope $scope = null): string {
        $disabledCited = array_values(array_filter(
            self::CITED_TOOLS,
            static fn (string $name): bool => !Mcp::isToolEnabled($name),
        ));

        return $this->baseInstructions()
            . $this->scopeNote($scope)
            . $this->availabilityNote($disabledCited)
            . $this->installNote(Mcp::settings()->additionalInstructions);
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
            Scope::Full => "\n\n## This Connection\n\nThis connection has FULL scope: every tool the server exposes on this install is available, including code execution and database tools. Prefer draft-mode writes and read-only queries unless the task requires more.",
        };
    }

    /**
     * Reconciles the base instructions with disabledTools: the base text
     * recommends CITED_TOOLS by name, and a disabled one among them is a
     * dead recommendation for this connection. Pure and static so it tests
     * without Craft settings; empty input means nothing to correct.
     *
     * @param string[] $disabledCited
     */
    private function availabilityNote(array $disabledCited): string {
        if ($disabledCited === []) {
            return '';
        }

        $names = implode(', ', array_map(static fn (string $name): string => "`{$name}`", $disabledCited));

        return "\n\n## Availability\n\nThe following tools mentioned above are disabled on this install and not available: {$names}.";
    }

    /**
     * The site owner's own text, appended absolutely last: after every note
     * this class computes, so it can contextualize or even contradict them,
     * which is the owner's call and their token budget. Pure and static so
     * it tests without Craft settings; blank input means nothing to add.
     */
    private function installNote(string $additionalInstructions): string {
        $trimmed = trim($additionalInstructions);
        if ($trimmed === '') {
            return '';
        }

        return "\n\n## This Install\n\n{$trimmed}";
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
