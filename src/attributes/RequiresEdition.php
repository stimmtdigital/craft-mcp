<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\attributes;

use Attribute;
use stimmt\craft\Mcp\enums\Edition;

/**
 * Marks a tool method or an entire tool class as requiring a minimum plugin
 * edition. A method-level attribute overrides a class-level one.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequiresEdition {
    public function __construct(
        public Edition $edition = Edition::Pro,
    ) {
    }
}
