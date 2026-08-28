<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\discovery;

use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\policy\Decision;
use stimmt\craft\Mcp\policy\Gate;

/**
 * Registers the elements this connection is allowed to have, and nothing else.
 *
 * WHY this replaces the SDK's own discovery loader: that one registers every
 * `#[McpTool]` it finds, and policy then had to unregister the ones it should
 * not have. Three things followed from that ordering, none of them wanted. The
 * server's advertised capabilities are computed inside `build()`, before the
 * filtering runs, so `initialize` described the unfiltered set. Each
 * `unregisterTool()` fires a list-changed event, dozens per request on a scoped
 * token, into a dispatcher that has no listeners. And a tool the SDK could see
 * but our own scan could not was live and ungoverned until a deny-by-default
 * sweep caught it, which is a filter that depends on a second filter.
 *
 * Asking first removes all three: what is not admitted is never registered, so
 * there is nothing to undo, nothing to announce, and no sweep to forget.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Loader implements LoaderInterface {
    /**
     * @param string[] $scanDirs
     */
    public function __construct(
        private string $basePath,
        private array $scanDirs,
        private CacheInterface $cache,
        private Gate $gate,
        private LoggerInterface $logger,
    ) {
    }

    public function load(RegistryInterface $registry): void {
        $discovered = $this->discover();

        $this->loadTools($registry, $discovered);
        $this->loadPrompts($registry, $discovered);
        $this->loadResources($registry, $discovered);
        $this->loadResourceTemplates($registry, $discovered);
    }

    private function discover(): DiscoveryState {
        $discoverer = new CachedDiscoverer(new Discoverer($this->logger), $this->cache, $this->logger);

        // No exclude list: the SDK excludes by directory name relative to each
        // scanned root, and none of the names we used to pass exist inside
        // tools, prompts or resources, so the list matched nothing while still
        // forming part of the cache key.
        return $discoverer->discover($this->basePath, $this->scanDirs, []);
    }

    private function loadTools(RegistryInterface $registry, DiscoveryState $discovered): void {
        $definitions = Mcp::getToolRegistry()->getDefinitions();

        foreach ($discovered->getTools() as $name => $reference) {
            $definition = $definitions[$name] ?? null;
            $decision = $definition === null
                ? $this->gate->admitsUnknown()
                : $this->gate->admitsTool($definition);

            if ($decision->substitutes()) {
                $registry->registerTool($this->inert($reference->tool, $decision), static fn (): string => (string) $decision->notice);

                continue;
            }

            if (!$decision->allowed) {
                $this->logger->debug("Not registering tool '{$name}': {$decision->reason}");

                continue;
            }

            $registry->registerTool($this->strict($reference->tool), $reference->handler);
        }
    }

    /**
     * The same tool, marked in its description and stripped back to a schema
     * that accepts anything.
     *
     * WHY the permissive schema: the real one would have the SDK reject a
     * malformed call with a validation error, and the caller would never reach
     * the notice explaining why the tool cannot do anything. A locked tool has
     * to be able to answer every call it is offered.
     */
    private function inert(Tool $tool, Decision $decision): Tool {
        return $this->rebuild(
            $tool,
            ['required' => []] + $tool->inputSchema,
            trim($decision->label . ' ' . ($tool->description ?? '')),
        );
    }

    /**
     * The tool with unknown arguments refused rather than dropped.
     *
     * The SDK builds a top-level schema of type, properties and required, and
     * never says whether anything else is allowed, so JSON Schema's default
     * applies and a name nobody declared is simply ignored. The tool then
     * answers as though the argument had never been passed:
     * count_entries(sectionHandle: 'pages') counted every entry in the install
     * and reported it as confidently as the spelling that works.
     *
     * That is the worst shape a wrong answer takes here, because nothing in the
     * response marks it, and it applies to every parameter of every tool. The
     * validator already runs, which is why the published minimums on limit and
     * offset are enforced; it was only ever missing this one key.
     *
     * Set here rather than on each tool so a new tool cannot be added without
     * it. Per-property permissiveness is untouched: the object parameters that
     * accept free-form keys, filters and relatedTo, declare that on themselves.
     */
    private function strict(Tool $tool): Tool {
        return $this->rebuild($tool, $tool->inputSchema + ['additionalProperties' => false], $tool->description);
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    private function rebuild(Tool $tool, array $inputSchema, ?string $description): Tool {
        return new Tool(
            name: $tool->name,
            title: $tool->title,
            // The discovered schema with nothing mandatory, so a call carrying
            // no arguments still reaches the notice.
            //
            // WHY reuse it rather than substitute a bare permissive one: the
            // SDK normalises an empty `properties` to an object when it builds
            // a schema, and a hand-written empty PHP array encodes as `[]`,
            // which its own validator then rejects with "properties must be an
            // object". The caller got a schema error instead of the sentence
            // explaining why the tool cannot run.
            inputSchema: $inputSchema + ['additionalProperties' => false],
            description: $description,
            annotations: $tool->annotations,
            icons: $tool->icons,
            meta: $tool->meta,
            outputSchema: $tool->outputSchema,
        );
    }

    private function loadPrompts(RegistryInterface $registry, DiscoveryState $discovered): void {
        $definitions = Mcp::getPromptRegistry()->getDefinitions();

        foreach ($discovered->getPrompts() as $name => $reference) {
            $definition = $definitions[$name] ?? null;
            $decision = $definition === null
                ? $this->gate->admitsUnknown()
                : $this->gate->admitsPrompt($definition);

            if (!$decision->allowed) {
                $this->logger->debug("Not registering prompt '{$name}': {$decision->reason}");

                continue;
            }

            $registry->registerPrompt($reference->prompt, $reference->handler, $reference->completionProviders);
        }
    }

    private function loadResources(RegistryInterface $registry, DiscoveryState $discovered): void {
        $definitions = $this->resourcesByUri(false);

        foreach ($discovered->getResources() as $uri => $reference) {
            if (!$this->admitsResourceAt($uri, $definitions)) {
                continue;
            }

            $registry->registerResource($reference->resource, $reference->handler);
        }
    }

    private function loadResourceTemplates(RegistryInterface $registry, DiscoveryState $discovered): void {
        $definitions = $this->resourcesByUri(true);

        foreach ($discovered->getResourceTemplates() as $uriTemplate => $reference) {
            if (!$this->admitsResourceAt($uriTemplate, $definitions)) {
                continue;
            }

            $registry->registerResourceTemplate(
                $reference->resourceTemplate,
                $reference->handler,
                $reference->completionProviders,
            );
        }
    }

    /**
     * The informational registry keys template definitions by name rather than
     * by URI, so both collections are re-indexed by the key the SDK uses.
     *
     * @return array<string, ResourceDefinition>
     */
    private function resourcesByUri(bool $templates): array {
        $byUri = [];
        foreach (Mcp::getResourceRegistry()->getDefinitions() as $definition) {
            if ($definition->isTemplate === $templates) {
                $byUri[$definition->uri] = $definition;
            }
        }

        return $byUri;
    }

    /**
     * @param array<string, ResourceDefinition> $definitions
     */
    private function admitsResourceAt(string $uri, array $definitions): bool {
        $definition = $definitions[$uri] ?? null;
        $decision = $definition === null
            ? $this->gate->admitsUnknown()
            : $this->gate->admitsResource($definition);

        if (!$decision->allowed) {
            $this->logger->debug("Not registering resource '{$uri}': {$decision->reason}");
        }

        return $decision->allowed;
    }
}
