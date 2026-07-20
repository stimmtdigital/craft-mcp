<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * What the tool filter should do with a single tool: keep it, hide it, or keep
 * it visible but replaced with an upgrade-message stub.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum ToolAction {
    case Keep;
    case Lock;
    case Hide;
}
