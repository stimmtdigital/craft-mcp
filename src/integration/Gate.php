<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

use Closure;
use craft\mcp\events\DiscoveringCapability;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ToolReference;
use ReflectionMethod;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;

/**
 * Decides which of the plugin's own capabilities Craft's MCP server offers.
 *
 * Runs on `craft\services\Mcp::EVENT_DISCOVERING_CAPABILITY`, after Craft's
 * own permission and token-scope checks, for the authenticated and stdio
 * servers. Capabilities that are not the plugin's are never touched: policing
 * Craft's own tools is not this plugin's business.
 *
 * A capability Craft does not discover is also not callable, because Craft
 * builds the server's registry from the filtered discovery.
 */
final readonly class Gate {
    private const string OWN_NAMESPACE = 'stimmt\\craft\\Mcp\\capabilities\\';

    public function __construct(
        private Settings $settings,
        private Edition $edition,
    ) {
    }

    public static function onDiscovering(DiscoveringCapability $event): void {
        if (!(new self(Mcp::settings(), Mcp::currentEdition()))->admits($event->reference)) {
            $event->allowed = false;
        }
    }

    public function admits(ElementReference $reference): bool {
        $method = $this->ownMethod($reference->handler);
        if ($method === null) {
            return true;
        }

        if (!$this->settings->enabled) {
            return false;
        }

        if ($reference instanceof ToolReference && in_array($reference->tool->name, $this->settings->disabledTools, true)) {
            return false;
        }

        if (!$this->settings->enableDangerousTools && $method->getAttributes(Dangerous::class) !== []) {
            return false;
        }

        return $this->edition->atLeast(self::requiredEdition($method));
    }

    /**
     * The edition a method needs: its own attribute wins over its class's,
     * and no attribute means Lite.
     */
    public static function requiredEdition(ReflectionMethod $method): Edition {
        $attributes = $method->getAttributes(RequiresEdition::class)
            ?: $method->getDeclaringClass()->getAttributes(RequiresEdition::class);

        return $attributes === [] ? Edition::Lite : $attributes[0]->newInstance()->edition;
    }

    /**
     * The reflected method behind a handler, when it is one of the plugin's
     * capability classes.
     *
     * @param Closure|array<mixed>|string $handler
     */
    private function ownMethod(Closure|array|string $handler): ?ReflectionMethod {
        if (!is_array($handler) || count($handler) !== 2 || !is_string($handler[1])) {
            return null;
        }

        $class = is_object($handler[0]) ? $handler[0]::class : $handler[0];
        if (!is_string($class) || !str_starts_with($class, self::OWN_NAMESPACE) || !method_exists($class, $handler[1])) {
            return null;
        }

        return new ReflectionMethod($class, $handler[1]);
    }
}
