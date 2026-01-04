<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\contracts;

/**
 * Interface for tool classes that are conditionally available.
 *
 * Implement this interface to make an entire tool class conditionally available.
 * The isAvailable() method is called during tool registration to determine
 * whether the class's tools should be registered.
 *
 * @deprecated Use ConditionalProvider instead. This interface is kept for backwards compatibility.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
interface ConditionalToolProvider extends ConditionalProvider {
}
