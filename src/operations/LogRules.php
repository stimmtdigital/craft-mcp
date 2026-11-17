<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;

/**
 * The verdict on what Craft's logs say about the recent past.
 */
final class LogRules {
    /**
     * @param bool $complete Whether the scan reached back over the whole window
     */
    public static function recentErrors(int $errors, int $hours, bool $complete): Check {
        $depth = $complete ? '' : ' (at least: the scan stopped before the start of the window)';

        if ($errors === 0) {
            return new Check('Logged errors', Level::Ok, "None in the last {$hours} hours.");
        }

        return new Check(
            'Logged errors',
            Level::Warning,
            "{$errors} error(s) logged in the last {$hours} hours{$depth}.",
            'Call stimmt_errors_summary to see them grouped by cause.',
        );
    }
}
