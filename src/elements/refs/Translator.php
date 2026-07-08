<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Craft;
use craft\base\FieldInterface;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\LayoutFields;

/**
 * Walks a serialized field-value map and applies the matching translator per
 * field; values without a translator pass through untouched (core
 * serializeValue/normalizeValue symmetry does the rest).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Translator {
    public function __construct(
        private Registry $registry,
    ) {
    }

    public static function withDefaults(?Keys $keys = null, ?Registry $registry = null): self {
        $keys ??= new Keys();
        $registry ??= new Registry();
        $translator = new self($registry);

        $registry->register(new Containers(
            fn (string $type, array $values, Context $context, bool $toKeys): array => $translator->translateBlock($type, $values, $context, $toKeys),
        ));
        $registry->register(new Link($keys));
        $registry->register(new Relations($keys));

        return $translator;
    }

    /**
     * @param array<string, FieldInterface> $fields
     */
    public function toKeys(array $fields, array $values, Context $context): array {
        return $this->translate($fields, $values, $context, toKeys: true);
    }

    /**
     * @param array<string, FieldInterface> $fields
     */
    public function toIds(array $fields, array $values, Context $context): array {
        return $this->translate($fields, $values, $context, toKeys: false);
    }

    public function translateBlock(string $typeHandle, array $values, Context $context, bool $toKeys): array {
        if ($typeHandle === '') {
            return $values;
        }

        $type = Craft::$app->getEntries()->getEntryTypeByHandle($typeHandle);
        if ($type === null) {
            return $values;
        }

        return $this->translate(LayoutFields::of($type->getFieldLayout()), $values, $context, $toKeys);
    }

    private function translate(array $fields, array $values, Context $context, bool $toKeys): array {
        foreach ($values as $handle => $value) {
            $field = $fields[$handle] ?? null;
            if ($field === null) {
                continue;
            }

            $fieldTranslator = $this->registry->for($field);
            if ($fieldTranslator === null) {
                continue;
            }

            $values[$handle] = $toKeys
                ? $fieldTranslator->toKeys($field, $value, $context)
                : $fieldTranslator->toIds($field, $value, $context);
        }

        return $values;
    }
}
