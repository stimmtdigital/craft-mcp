<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use craft\base\FieldInterface;
use craft\models\FieldLayout;

/**
 * The single home of field-layout enumeration. Always resolves through the
 * layout so per-layout handle overrides and multi-instance fields work;
 * never consults the global Fields service.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class LayoutFields {
    /**
     * @return array<string, FieldInterface> effective clones keyed by effective handle
     */
    public static function of(?FieldLayout $layout): array {
        if ($layout === null) {
            return [];
        }

        $fields = [];
        foreach ($layout->getCustomFields() as $field) {
            $fields[(string) $field->handle] = $field;
        }

        return $fields;
    }
}
