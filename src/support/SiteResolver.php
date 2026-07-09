<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\models\Site;
use Mcp\Exception\ToolCallException;

/**
 * Resolves an optional site handle to a Site. Null means "Craft's default
 * site behavior"; an unknown handle is a caller error and fails loudly.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SiteResolver {
    public static function resolve(?string $site): ?Site {
        if ($site === null) {
            return null;
        }

        $model = Craft::$app->getSites()->getSiteByHandle($site);
        if ($model === null) {
            throw new ToolCallException("Site '{$site}' not found. Use list_sites for available handles.");
        }

        return $model;
    }
}
