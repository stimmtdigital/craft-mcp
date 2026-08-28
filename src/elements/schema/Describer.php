<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use craft\fieldlayoutelements\BaseNativeField;
use craft\fieldlayoutelements\CustomField;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;

/**
 * Walks a field layout into a schema description: custom fields with their
 * layout-level overrides, native layout fields, Matrix block types with
 * depth-limited expansion (depth > 0 expands sub-fields one level shallower
 * per recursion; a matrix whose depth budget is exhausted still names its
 * block types, at any nesting level, not only at the top).
 * Every field also carries a machine-readable input shape from Shape, which
 * is the single source of the field's kind. For Matrix fields, `blockTypes`
 * is the ONLY place nested block-type structure (fields, instructions) is
 * expanded; the field's own `input` carries just a flat set of block-type
 * handles, never a second recursive copy of the same tree.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Describer {
    private Shape $shape;

    private Translation $translation;

    private Target $target;

    /**
     * @param bool $multiSite whether the install serves more than one site. Told
     *                        rather than looked up: describing a layout is pure
     *                        work over the models it is handed, and the caller
     *                        is the one that already has an application to ask.
     */
    public function __construct(?Shape $shape = null, private bool $multiSite = false) {
        $this->shape = $shape ?? new Shape();
        $this->translation = new Translation();
        $this->target = new Target();
    }

    public function describe(?FieldLayout $layout, int $depth = 1): array {
        return $this->fields($layout, $depth);
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

    private function fields(?FieldLayout $layout, int $depth): array {
        if ($layout === null) {
            return [];
        }

        return array_map(
            fn (CustomField $element): array => $this->field($element, $depth),
            array_values($layout->getCustomFieldElements()),
        );
    }

    private function field(CustomField $element, int $depth): array {
        $field = $element->getField();
        $input = $this->shape->of($field, $depth + 1);

        $described = [
            'handle' => (string) $field->handle,
            'name' => (string) $field->name,
            'type' => $field::class,
            'kind' => $input['kind'],
            'instructions' => $field->instructions ?? '',
            'required' => $element->required,
            'input' => $input,
        ];

        // Only where the question exists. On a single-site install there is no
        // "other site" to leak into, and the key would be pure token cost on
        // every field of every schema an agent reads.
        if ($this->multiSite) {
            $described['translation'] = $this->translation->of($field);
        }

        if ($field instanceof BaseRelationField) {
            $described['target'] = $this->target->of($field);
        }

        if ($field instanceof Matrix) {
            $described['blockTypes'] = $depth > 0
                ? $this->expandedBlockTypes($field, $depth - 1)
                : $this->namedBlockTypes($field);
        }

        return $described;
    }

    private function expandedBlockTypes(Matrix $field, int $depth): array {
        return array_map(
            fn (EntryType $type): array => [
                'handle' => (string) $type->handle,
                'name' => (string) $type->name,
                'hasTitleField' => $type->hasTitleField,
                'fields' => $this->fields($type->getFieldLayout(), $depth),
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
