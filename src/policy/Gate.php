<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\policy;

use Craft;
use craft\elements\User;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\PromptDefinition;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\models\ToolDefinition;

/**
 * The one place that answers "may this connection see this element".
 *
 * WHY it exists: that question used to be spread across `Mcp::isToolEnabled()`,
 * `Scope::allows()`, a privileged check on the server factory, a deny-by-default
 * sweep, and prompt and resource copies of the same shape. Five places, which
 * meant adding an axis meant touching five places and hoping they agreed. They
 * are one method per element kind now, and a new axis is a new clause.
 *
 * The Gate is deliberately constructible without a Builder or a Registry: the
 * `list_mcp_tools` row reports whether each tool is enabled, and it should read
 * the same answer the server acts on rather than re-deriving a second one that
 * can drift.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Gate {
    /**
     * The scope is public because the Gate is the only thing that knows which
     * connection this is, and "what am I connected as" is a question the caller
     * is entitled to ask. Null means stdio, which carries no token and is
     * therefore unscoped.
     */
    public function __construct(public ?Scope $scope = null) {
    }

    public function admitsTool(ToolDefinition $definition): Decision {
        if (!Mcp::isToolEnabled($definition->name)) {
            return Decision::deny('disabled by settings, an unmet condition, or the dangerous-tools gate');
        }

        if ($this->scope !== null && !$this->scope->allows($definition->category, $definition->dangerous)) {
            return Decision::deny("outside the {$this->scope->value} scope");
        }

        if (!$this->privileged($definition)) {
            return Decision::deny('an install-introspection tool, and this connection is neither full scope nor an admin');
        }

        return Decision::keep();
    }

    public function admitsPrompt(PromptDefinition $definition): Decision {
        // Prompts carry no scope semantics; that stays a tool-only axis.
        return Mcp::isPromptEnabled($definition->name)
            ? Decision::keep()
            : Decision::deny('disabled by settings or an unmet condition');
    }

    /**
     * Resources carry no registration-time privilege axis. The ones that expose
     * install internals assert `Authorization::assertPrivileged` in their own
     * bodies instead, so they are listed and then refused on read rather than
     * hidden. That is a deliberate difference from tools, not an omission here.
     */
    public function admitsResource(ResourceDefinition $definition): Decision {
        return Mcp::isResourceEnabled($definition->uri)
            ? Decision::keep()
            : Decision::deny('disabled by settings or an unmet condition');
    }

    /**
     * Anything the SDK registered that we have no definition for has passed
     * none of the checks above, because it was never offered to them. Denying
     * it is the difference between a filter and a suggestion.
     */
    public function admitsUnknown(): Decision {
        return Decision::deny('registered by attribute discovery but absent from the informational registry');
    }

    private function privileged(ToolDefinition $definition): bool {
        if (!$definition->privileged) {
            return true;
        }
        if ($this->privilegedAllowed()) {
            return true;
        }

        return in_array($definition->name, Mcp::settings()->scopedTokenPrivilegedTools, true);
    }

    /**
     * Install introspection is hidden from a scoped token whose user is not an
     * admin. Full scope and stdio are never gated on this axis.
     */
    private function privilegedAllowed(): bool {
        if ($this->scope === null || $this->scope === Scope::Full) {
            return true;
        }

        $identity = Craft::$app->getUser()->getIdentity();

        return $identity instanceof User && $identity->admin;
    }
}
