<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use craft\base\FieldInterface;
use craft\fields\BaseRelationField;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\Warning;

/**
 * Translates relation-field id arrays to natural keys and back. The field's
 * static elementType() names the target, so identical key shapes stay
 * unambiguous. Unknown target types pass through as ids silently.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Relations implements FieldTranslator {
    public function __construct(
        private Keys $keys,
    ) {
    }

    public function handles(FieldInterface $field): bool {
        return $field instanceof BaseRelationField;
    }

    public function toKeys(FieldInterface $field, mixed $value, Context $context): mixed {
        if (!is_array($value)) {
            return $value;
        }

        /** @var BaseRelationField $field */
        $target = $field::elementType();
        if (!$this->keys->supports($target)) {
            return $value;
        }

        return array_map(
            fn (mixed $id): mixed => is_numeric($id)
                ? ($this->keys->keyFor($target, (int) $id, $context->site) ?? (int) $id)
                : $id,
            array_values($value),
        );
    }

    public function toIds(FieldInterface $field, mixed $value, Context $context): mixed {
        if (!is_array($value)) {
            return $value;
        }

        /** @var BaseRelationField $field */
        $target = $field::elementType();
        $ids = [];
        foreach (array_values($value) as $index => $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item;
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $id = $this->keys->idFor($target, $item, $context->site);
            if ($id !== null) {
                $ids[] = $id;
                continue;
            }

            $shortName = strtolower(substr((string) strrchr('\\' . $target, '\\'), 1));
            $context->warn(new Warning(
                (string) $field->handle,
                $field->handle . '.' . $index,
                $item,
                'No ' . $shortName . ' matches this key',
            ));
        }

        return $ids;
    }
}
