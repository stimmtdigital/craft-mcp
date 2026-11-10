<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\attributes;

use Attribute;

/**
 * Marks a tool that runs code, writes through SQL or GraphQL, or creates
 * files on the server. Such tools are offered only while
 * `enableDangerousTools` is on, which production forces off unless the
 * config file sets it explicitly.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Dangerous {
}
