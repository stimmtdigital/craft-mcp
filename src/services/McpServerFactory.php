<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
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
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\support\FileLogger;
use stimmt\craft\Mcp\support\Psr11ContainerAdapter;

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
        $registry = new Registry(null, $this->logger ?? new NullLogger());

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
            )
            ->setContainer($this->container)
            ->setRegistry($registry);

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
        foreach (Mcp::getToolRegistry()->getDefinitions() as $definition) {
            $allowed = Mcp::isToolEnabled($definition->name)
                && ($scope === null || $scope->allows($definition->category, $definition->dangerous));

            if (!$allowed) {
                $registry->unregisterTool($definition->name);
            }
        }
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

## Best Practices

1. Use `list_*` tools to explore available data before making changes
2. Use `get_*` tools to inspect specific items
3. Use read-only queries before mutations
4. Tools marked with a destructive annotation modify data or execute code; prefer draft-mode writes and review flows
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
