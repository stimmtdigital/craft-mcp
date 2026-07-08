<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Craft;
use craft\base\FieldInterface;
use craft\fields\Addresses;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\models\FieldLayout;
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
            fn (FieldInterface $field, string $type, array $values, Context $context, bool $toKeys): array => $translator->translateBlock($field, $type, $values, $context, $toKeys),
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

    public function translateBlock(FieldInterface $field, string $typeHandle, array $values, Context $context, bool $toKeys): array {
        $layout = $this->blockLayout($field, $typeHandle);
        if ($layout === null) {
            return $values;
        }

        return $this->translate(LayoutFields::of($layout), $values, $context, $toKeys);
    }

    /**
     * Matrix blocks carry an entry-type handle; a ContentBlock value uses the
     * field's own layout; Addresses use the shared Address element layout.
     * Unknown containers resolve to null, so their values pass through.
     */
    private function blockLayout(FieldInterface $field, string $typeHandle): ?FieldLayout {
        if ($field instanceof Matrix) {
            return $typeHandle === ''
                ? null
                : Craft::$app->getEntries()->getEntryTypeByHandle($typeHandle)?->getFieldLayout();
        }

        if ($field instanceof ContentBlock) {
            return $field->getFieldLayout();
        }

        if ($field instanceof Addresses) {
            return Craft::$app->getAddresses()->getFieldLayout();
        }

        return null;
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
