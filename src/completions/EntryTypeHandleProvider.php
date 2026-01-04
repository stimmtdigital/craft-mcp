<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use craft\models\EntryType;
use stimmt\craft\Mcp\services\SchemaHelper;

/**
 * Provides completion values for entry type handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EntryTypeHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        $allEntryTypes = SchemaHelper::getAllEntryTypes();

        $handles = array_map(
            /** @param array{section: \craft\models\Section, entryType: EntryType} $item */
            fn (array $item): string => $item['entryType']->handle ?? '',
            $allEntryTypes,
        );

        return array_values(array_unique(array_filter($handles)));
    }
}
