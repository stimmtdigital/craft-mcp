<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stimmt\craft\Mcp\discovery\Loader;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\logging\FileLogger;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\pipeline\ErrorBoundary;
use stimmt\craft\Mcp\pipeline\Freshness;
use stimmt\craft\Mcp\pipeline\Presenter;
use stimmt\craft\Mcp\policy\Gate;
use stimmt\craft\Mcp\psr\Cache;
use stimmt\craft\Mcp\psr\Container;
use stimmt\craft\Mcp\psr\Dispatcher;
use stimmt\craft\Mcp\support\Build;
use stimmt\craft\Mcp\support\ConsoleHeaders;
use stimmt\craft\Mcp\support\DiscoveryCache;
use stimmt\craft\Mcp\support\SignalHandler;
use stimmt\craft\Mcp\support\Subscription;
use stimmt\craft\Mcp\text\Palette;
use stimmt\craft\Mcp\text\Renderer;
use stimmt\craft\Mcp\transport\Buffered;
use stimmt\craft\Mcp\transport\Stdio;

/**
 * Factory for creating MCP Server instances.
 *
 * Follows DIP: depends on abstractions (ContainerInterface, registries via McpRegistry facade).
 * Follows SRP: sole responsibility is building properly configured Server instances.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class McpServerFactory {
    /**
     * The revisions this server will serve over HTTP. 2024-11-05 is omitted on
     * purpose: it predates the Streamable HTTP transport it would arrive on.
     */
    private const array SUPPORTED_PROTOCOLS = [
        ProtocolVersion::V2025_03_26,
        ProtocolVersion::V2025_06_18,
        ProtocolVersion::V2025_11_25,
    ];

    /**
     * Where a client can send someone who wants to know what this server is.
     */
    private const string WEBSITE_URL = 'https://github.com/stimmtdigital/craft-mcp';

    public function __construct(private readonly ?ContainerInterface $container = new Container(), private readonly ?LoggerInterface $logger = null) {
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
        $eventDispatcher = new Dispatcher();
        $registry = new Registry($eventDispatcher, $logger);

        $basePath = dirname(__DIR__);

        $builder = Server::builder()
            // Description and website URL are part of the identity the SDK
            // serializes on every initialize, and clients show them in their
            // server pickers. Passing name and version only left both blank.
            ->setServerInfo(
                name: 'Craft CMS MCP Server',
                version: $this->version(),
                description: 'Read and write Craft CMS content, inspect the schema, and query the install.',
                websiteUrl: self::WEBSITE_URL,
            )
            ->setInstructions($this->getInstructions($scope))
            // Answered on every initialize. Without it the SDK replies with
            // whatever it was compiled against, which is not necessarily what
            // this server implements.
            ->setProtocolVersion(ProtocolVersion::V2025_06_18)
            ->addLoader(new Loader(
                basePath: $basePath,
                scanDirs: ['tools', 'prompts', 'resources'],
                cache: $this->discoveryCache($basePath),
                gate: new Gate($scope),
                logger: $logger,
            ))
            // Ours rather than the SDK's default, because it owns the stored
            // subscription keys: a client that subscribes to a resource
            // template was told yes and then heard nothing, since the notifier
            // only ever produces concrete URIs and the default matches by
            // exact key.
            ->setResourceSubscriptionManager(new Subscription())
            ->setCapabilities($this->capabilities())
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

        // No post-build filtering: the loader admits or declines each element
        // before it is registered, so capabilities are computed on the real set
        // and nothing has to be unregistered afterwards.
        return $builder->build();
    }

    /**
     * What this server actually honours, rather than what the SDK infers.
     *
     * Left to itself the builder turns on every listChanged flag purely because
     * an event dispatcher exists, and a client that trusts those waits for
     * notifications that are never sent. Only the tool list has a producer:
     * reload_mcp pushes one after a rescan. Prompts and resources have none, so
     * a client is better told to poll than told to wait.
     *
     * Subscriptions and logging stay on because they now work on both
     * transports; until the fiber suspension was fixed they were advertised and
     * broken over HTTP, which is the same lie in the other direction.
     */
    private function capabilities(): ServerCapabilities {
        return new ServerCapabilities(
            tools: true,
            toolsListChanged: true,
            resources: true,
            resourcesSubscribe: true,
            resourcesListChanged: false,
            prompts: true,
            promptsListChanged: false,
            logging: true,
            // Real: resource templates and prompts carry completion providers
            // for section handles, and the SDK resolves them. Only tool
            // arguments have no completion channel in MCP at all.
            completions: true,
        );
    }

    /**
     * The cache behind the SDK's attribute discovery. Keying it on the state
     * of the scanned code is what stops tools/list serving a previous scan
     * after a tool is edited; DiscoveryCache holds that reasoning.
     */
    private function discoveryCache(string $basePath): Cache {
        return (new DiscoveryCache(
            cache: Craft::$app->getCache(),
            devMode: Craft::$app->getConfig()->getGeneral()->devMode,
            version: Build::revision(),
        ))->of($basePath);
    }

    private function version(): string {
        return Mcp::getInstance()?->getVersion() ?? '1.0.0';
    }

    /**
     * The output layer, decorating the SDK's own reference handler so every
     * tool call passes through it: one payload on the wire instead of two, and
     * central text rendering for tools that declare the output parameter.
     * Built here rather than in the Builder default so the container stays the
     * one this factory already configures.
     */
    private function presenter(): ReferenceHandlerInterface {
        // Order is the design, not an accident. ErrorBoundary is outermost so
        // it also covers argument preparation, result formatting and the
        // Presenter's own logic, none of which a tool body can guard.
        return new ErrorBoundary(
            new Freshness(
                new Presenter(
                    new ReferenceHandler($this->container),
                    new Renderer(Palette::fromSettings()),
                ),
            ),
        );
    }

    /**
     * Create a StdioTransport for the server.
     *
     * The console request is given a headers shim on the way past, because this
     * is the one seam that means "we are serving over stdio". Third-party
     * listeners on Craft's own events assume a web request and take the whole
     * tool call down with them otherwise; ConsoleHeaders holds the reasoning.
     * The HTTP transport is built separately and never sees it.
     */
    public function createTransport(?SignalHandler $signals = null): StdioTransport {
        $request = Craft::$app->getRequest();
        if ($request->getBehavior(ConsoleHeaders::NAME) === null) {
            $request->attachBehavior(ConsoleHeaders::NAME, new ConsoleHeaders());
        }

        return new Stdio($signals ?? new SignalHandler(), $this->logger);
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
            // CORS is not installed: the controller answers OPTIONS itself,
            // before any middleware runs, so the preflight branch could never
            // be reached and an installed-but-unreachable guard reads as
            // protection that is not there. Browser clients need both halves
            // changed together, which is a deliberate decision, not this.
            new DnsRebindingProtectionMiddleware(allowedHosts: ['localhost', '127.0.0.1', '[::1]', strtolower($hostName)]),
            // Given no list the middleware admits every revision the SDK knows,
            // including 2024-11-05, which predates Streamable HTTP entirely.
            new ProtocolVersionMiddleware(self::SUPPORTED_PROTOCOLS),
        ];

        return new Buffered($request, logger: $this->logger, middleware: $middleware);
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
        'create_nested_entry',
        'move_nested_entry',
        'delete_entry',
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
4. Single Matrix-block work has dedicated tools: `create_nested_entry` adds one block to an owner's field without resending its siblings, `move_nested_entry` repositions one, and `update_entry`/`delete_entry` with the block's own id edit or remove one. Prefer these over rewriting the owner's whole field value, which deletes any block left out of the payload.
5. Writes land as DRAFTS by default: the response carries `draftElementId` and a `cpEditUrl` deep link for human review; `publish_entry` makes them live. Nothing touches live content until published.
6. Always read the `warnings` list on write responses: unresolvable natural keys become warnings, never guesses or silent drops. Validation failures return per-field errors.
7. Multi-site installs: pass the `site` handle parameter; `copy_entry_to_site` moves content between sites.

The full contract lives in the `craft://guides/content-writing` resource.

## Choosing Tools

Prefer the most specific tool and escalate only when none fits:

1. Content questions: `list_entries` (field filters, `relatedTo`, `search`, date ranges, `fields` projection), `get_entry`, `count_entries` for totals and per-value breakdowns, `list_drafts` for the review queue, `list_revisions` for an entry's history, `describe_entry_schema` for payload shapes.
2. Single Matrix-block changes: `create_nested_entry` to add a block, `move_nested_entry` to reposition one, `update_entry`/`delete_entry` with the block's own id to edit or remove one. Never rebuild an owner's whole field value to change one block.
3. Other element types and nested shapes: `query_graphql` reads anything Craft's GraphQL schema exposes (assets, categories, users, plugin types) with exactly the response shape you ask for.
4. Database structure: `get_database_schema` and `get_table_counts`, never hand-written information_schema queries.
5. `run_query` covers what no structured tool does: custom plugin tables and aggregate SQL (SELECT only).
6. `tinker` is the last resort, for logic no tool can express (cross-entry computation, service calls). Keep analysis code read-only; write content through the entry tools so drafts, validation, and warnings stay in play.
7. Tools marked with a destructive annotation modify data or execute code; prefer draft-mode writes and review flows.
INSTRUCTIONS;
    }

    private function registerExternalElements(Builder $builder): void {
        $this->registerExternalTools($builder);
        $this->registerExternalPrompts($builder);
        $this->registerExternalResources($builder);
    }

    /**
     * Everything the author declared in their attribute travels with the tool.
     * Passing handler, name and description only meant a third party's read-only
     * tool reached clients under the conservative destructive defaults, and its
     * title, icons, _meta and output schema were dropped on the floor.
     * inputSchema stays absent on purpose: the SDK generates it from the
     * handler's own signature, which is more accurate than anything we carry.
     */
    private function registerExternalTools(Builder $builder): void {
        foreach (McpRegistry::tools()->getExternalToolDefinitions() as $def) {
            $builder->addTool(
                handler: [$def->class, $def->method],
                name: $def->name,
                title: $def->title,
                description: $def->description,
                annotations: $def->annotations,
                icons: $def->icons,
                meta: $def->meta,
                outputSchema: $def->outputSchema,
            );
        }
    }

    private function registerExternalPrompts(Builder $builder): void {
        foreach (McpRegistry::prompts()->getExternalPromptDefinitions() as $def) {
            $builder->addPrompt(
                handler: [$def->class, $def->method],
                name: $def->name,
                title: $def->title,
                description: $def->description,
                icons: $def->icons,
                meta: $def->meta,
            );
        }
    }

    private function registerExternalResources(Builder $builder): void {
        foreach (McpRegistry::resources()->getExternalResourceDefinitions() as $def) {
            $this->registerResource($builder, $def);
        }
    }

    /**
     * Templates take no size and no icons, because #[McpResourceTemplate]
     * declares neither; everything else the author wrote travels through.
     */
    private function registerResource(Builder $builder, ResourceDefinition $def): void {
        if ($def->isTemplate) {
            $builder->addResourceTemplate(
                handler: [$def->class, $def->method],
                uriTemplate: $def->uri,
                name: $def->name,
                title: $def->title,
                description: $def->description,
                mimeType: $def->mimeType,
                annotations: $def->annotations,
                meta: $def->meta,
            );

            return;
        }

        $builder->addResource(
            handler: [$def->class, $def->method],
            uri: $def->uri,
            name: $def->name,
            title: $def->title,
            description: $def->description,
            mimeType: $def->mimeType,
            size: $def->size,
            annotations: $def->annotations,
            icons: $def->icons,
            meta: $def->meta,
        );
    }
}
