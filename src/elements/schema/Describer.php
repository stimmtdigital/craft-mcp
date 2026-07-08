<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Addresses;
use craft\fields\BaseRelationField;
use craft\fields\ContentBlock;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;

/**
 * Walks a field layout into a schema description: custom fields with their
 * layout-level overrides, native layout fields, Matrix block types with
 * depth-limited expansion (depth > 0 expands sub-fields one level shallower
 * per recursion; a top-level matrix at depth 0 still names its block types).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Describer {
    public function describe(?FieldLayout $layout, int $depth = 1): array {
        return $this->fields($layout, $depth, top: true);
    }

    public function natives(?FieldLayout $layout): array {
        if ($layout === null) {
            return [];
        }

        $natives = [];
        foreach ($layout->getElementsByType(BaseNativeField::class) as $element) {
            /** @var BaseNativeField $element */
            $natives[] = [
                'attribute' => $element->attribute(),
                'name' => (string) ($element->label ?? ''),
                'required' => (bool) $element->required,
                'mandatory' => $element->mandatory(),
            ];
        }

        return $natives;
    }

    private function fields(?FieldLayout $layout, int $depth, bool $top): array {
        if ($layout === null) {
            return [];
        }

        return array_map(
            fn (CustomField $element): array => $this->field($element, $depth, $top),
            array_values($layout->getCustomFieldElements()),
        );
    }

    private function field(CustomField $element, int $depth, bool $top): array {
        $field = $element->getField();

        $described = [
            'handle' => (string) $field->handle,
            'name' => (string) $field->name,
            'type' => $field::class,
            'kind' => $this->kind($field),
            'instructions' => $field->instructions ?? '',
            'required' => $element->required,
        ];

        if ($field instanceof BaseRelationField) {
            $target = [
                'elementType' => $field::elementType(),
                'sources' => $field->getInputSources(),
            ];

            $described['target'] = $target;
        }

        if ($field instanceof Matrix) {
            $described['blockTypes'] = $depth > 0
                ? $this->expandedBlockTypes($field, $depth - 1)
                : ($top ? $this->namedBlockTypes($field) : []);
        }

        return $described;
    }

    private function kind(FieldInterface $field): string {
        return match (true) {
            $field instanceof Matrix => 'matrix',
            $field instanceof BaseRelationField => 'relation',
            $field instanceof Link => 'link',
            $field instanceof ContentBlock, $field instanceof Addresses => 'container',
            default => 'plain',
        };
    }

    private function expandedBlockTypes(Matrix $field, int $depth): array {
        return array_map(
            fn (EntryType $type): array => [
                'handle' => (string) $type->handle,
                'name' => (string) $type->name,
                'hasTitleField' => $type->hasTitleField,
                'fields' => $this->fields($type->getFieldLayout(), $depth, top: false),
            ],
            $field->getEntryTypes(),
        );
    }

    private function namedBlockTypes(Matrix $field): array {
        return array_map(
            static fn (EntryType $type): array => [
                'handle' => (string) $type->handle,
                'name' => (string) $type->name,
            ],
            $field->getEntryTypes(),
        );
    }
}
