<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Schema\Content\Content;

/**
 * The size budget for a single tool result.
 *
 * A response larger than the transport's socket buffer cannot be handed over in
 * one write, and the stdio server blocks inside that write until the client
 * drains it. When the client is itself mid-batch, writing further requests into
 * a stdin nobody is reading, neither side can move and the call never returns
 * and never errors.
 *
 * This does not fix that. The write path does, by never blocking indefinitely,
 * and until it does a normal `list_entries` at the default page size is already
 * about twice the size that can deadlock, so a budget low enough to prevent it
 * would refuse the plugin's own defaults. What this does prevent is the
 * pathological end: a tinker call returning a whole object graph, or a schema
 * dump measured in megabytes, where refusing with a sentence the caller can act
 * on beats emitting something nothing downstream can use.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Payload {
    /**
     * The refusal for a result that will not fit, or null when it fits.
     *
     * Takes the budget rather than reading the setting, so the decision can be
     * exercised without booting Craft and the caller keeps the one dependency
     * on configuration.
     *
     * @param Content[] $content
     */
    public static function overBudget(array $content, int $budget): ?string {
        if ($budget <= 0) {
            return null;
        }

        $bytes = self::bytes($content);
        if ($bytes <= $budget) {
            return null;
        }

        return sprintf(
            'This result is %s and the limit is %s, so it was not sent. Ask for less of it: '
            . 'lower limit, pass fields to return only the attributes you need, or narrow the query. '
            . 'Raise maxResponseBytes in the plugin settings if the whole payload is genuinely needed.',
            self::human($bytes),
            self::human($budget),
        );
    }

    /**
     * The encoded size, as close to what goes on the wire as we can measure
     * without encoding it twice. An unencodable payload is treated as fitting:
     * this guard is not the place that failure should surface.
     *
     * @param Content[] $content
     */
    private static function bytes(array $content): int {
        $encoded = json_encode($content);

        return $encoded === false ? 0 : strlen($encoded);
    }

    private static function human(int $bytes): string {
        return $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : sprintf('%d KB', (int) round($bytes / 1024));
    }
}
