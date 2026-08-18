<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\pipeline;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\support\ConfigFreshness;

/**
 * Runs the project-config freshness probe once per tool call, with a real
 * request context.
 *
 * WHY it moved here: the probe lived inside the wrapper every tool body opens,
 * and it only notifies subscribers when it is handed a RequestContext. Of 62
 * call sites, 4 passed one. Ten more captured the context into their closure
 * for their own use and still did not hand it on. So the probe ran constantly
 * and could tell nobody, which is the least useful state available: all of the
 * cost, none of the point.
 *
 * The handler receives the session and request the SDK injects on every call,
 * so building the context here is the one place it is always available.
 *
 * Tools only. A prompt or a resource read cannot change project config, and
 * probing on them would be new behaviour dressed up as a refactor.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Freshness implements ReferenceHandlerInterface {
    public function __construct(private ReferenceHandlerInterface $handler) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed {
        if ($reference instanceof ToolReference) {
            ConfigFreshness::ensure($this->context($arguments));
        }

        return $this->handler->handle($reference, $arguments);
    }

    /**
     * The SDK injects both under reserved keys before the handler is reached
     * (CallToolHandler), and strips them again while mapping arguments onto the
     * tool's own parameters, so a tool never sees them.
     *
     * @param array<string, mixed> $arguments
     */
    private function context(array $arguments): ?RequestContext {
        $session = $arguments['_session'] ?? null;
        $request = $arguments['_request'] ?? null;

        return $session === null || $request === null
            ? null
            : new RequestContext($session, $request);
    }
}
