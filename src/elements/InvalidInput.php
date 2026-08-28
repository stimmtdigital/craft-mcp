<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use InvalidArgumentException;

/**
 * Input this module can name as the CALLER's mistake: a date that is not one,
 * a field handle nothing answers to, a groupBy that would not add up.
 *
 * WHY a type of its own rather than a bare InvalidArgumentException: the error
 * boundary decides whether a message is worth showing as written or needs a
 * class name and a file and line appended, and it can only tell those apart by
 * type. The module speaks to agents through the tools that call it, so the
 * whole value of these refusals is the sentence they carry; "InvalidArgument
 * Exception: ... (Filters.php:119)" buries that sentence under a frame the
 * caller cannot act on, while the handle guards next to it answer in plain
 * prose. Craft throws the bare SPL type from a hundred places of its own, and
 * those ARE ours to debug, so they must keep their location.
 *
 * It stays inside src/elements because this module is kept free of the MCP
 * SDK, which is where the tool boundary's own exception type lives.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class InvalidInput extends InvalidArgumentException {
}
