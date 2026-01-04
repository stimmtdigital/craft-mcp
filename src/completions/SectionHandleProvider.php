<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Craft;
use craft\models\Section;
use craft\services\Entries;

/**
 * Provides completion values for section handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SectionHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section[] $sections */
        $sections = $entriesService->getAllSections();

        return array_values(array_map(
            fn (Section $section): string => $section->handle ?? '',
            $sections,
        ));
    }
}
