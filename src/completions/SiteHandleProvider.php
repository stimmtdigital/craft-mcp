<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Craft;
use craft\models\Site;
use craft\services\Sites;

/**
 * Provides completion values for site handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SiteHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        /** @var Sites $sitesService */
        $sitesService = Craft::$app->getSites();

        /** @var Site[] $sites */
        $sites = $sitesService->getAllSites();

        return array_values(array_map(
            fn (Site $site): string => $site->handle ?? '',
            $sites,
        ));
    }
}
