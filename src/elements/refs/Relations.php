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

        $keyed = [];
        foreach (array_values($value) as $index => $id) {
            if (!is_numeric($id)) {
                $keyed[] = $id;

                continue;
            }

            $key = $this->keys->keyFor($target, (int) $id, $context->site);
            if ($key !== null) {
                $keyed[] = $key;

                continue;
            }

            // Falling back to the raw id is right: it is the only thing left
            // that still addresses the element. Doing it silently was not. The
            // whole payload contract is that a relation reads as a key and can
            // be written straight back, so an id here means that round trip
            // will not hold for this item, and the caller is entitled to know
            // which item and why rather than inferring it from a type.
            $keyed[] = (int) $id;
            $context->warn(new Warning(
                (string) $field->handle,
                $field->handle . '.' . $index,
                ['id' => (int) $id],
                'No natural key for this id; it reads as a raw id and may not resolve on write',
            ));
        }

        return $keyed;
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

            $resolution = $this->keys->resolve($target, $item, $context->site);
            if ($resolution->id !== null) {
                $ids[] = $resolution->id;
                continue;
            }

            $shortName = strtolower(substr((string) strrchr('\\' . $target, '\\'), 1));
            $context->warn(new Warning(
                (string) $field->handle,
                $field->handle . '.' . $index,
                $item,
                $resolution->explain($shortName),
            ));
        }

        return $ids;
    }
}
