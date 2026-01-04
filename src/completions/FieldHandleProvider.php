<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Craft;
use craft\base\FieldInterface;
use craft\services\Fields;

/**
 * Provides completion values for field handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class FieldHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        /** @var Fields $fieldsService */
        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface[] $fields */
        $fields = $fieldsService->getAllFields();

        return array_values(array_map(
            fn (FieldInterface $field): string => $field->handle ?? '',
            $fields,
        ));
    }
}
