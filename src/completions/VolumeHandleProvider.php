<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Craft;
use craft\models\Volume;
use craft\services\Volumes;

/**
 * Provides completion values for asset volume handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class VolumeHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        /** @var Volumes $volumesService */
        $volumesService = Craft::$app->getVolumes();

        /** @var Volume[] $volumes */
        $volumes = $volumesService->getAllVolumes();

        return array_values(array_map(
            fn (Volume $volume): string => $volume->handle ?? '',
            $volumes,
        ));
    }
}
