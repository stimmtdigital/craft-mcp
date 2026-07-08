<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\Link as LinkField;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Link as LinkRef;
use Throwable;

/**
 * Resolves a machine-readable input descriptor for a field, mirroring the
 * elements module's own payload contract (natural keys for relations, block
 * handles for Matrix, ref-string links, etc.). Core structural bases are
 * matched by instanceof BEFORE any duck-typed capability probe so a core
 * class can never be swallowed by a probe; third-party nesting (Hyper link
 * types, layout-backed fields) is reached generically through those probes,
 * never by naming a plugin class. No GraphQL, no per-type mappers.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Shape {
    public function __construct(
        private Keys $keys = new Keys(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(FieldInterface $field, int $depth = 2): array {
        try {
            return $this->resolve($field, $depth);
        } catch (Throwable $e) {
            return $this->scalar($field) + [
                'note' => 'structure introspection failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function ofLayout(?FieldLayout $layout, int $depth = 2): array {
        if ($layout === null) {
            return ['native' => [], 'fields' => []];
        }

        $native = [];
        foreach ($layout->getElementsByType(BaseNativeField::class) as $element) {
            /** @var BaseNativeField $element */
            $native[] = ['attribute' => $element->attribute(), 'required' => (bool) $element->required];
        }

        $fields = [];
        foreach (LayoutFields::of($layout) as $handle => $field) {
            $fields[$handle] = ['input' => $this->of($field, $depth)];
        }

        return ['native' => $native, 'fields' => $fields];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(FieldInterface $field, int $depth): array {
        if ($depth <= 0) {
            return ['kind' => 'nested', 'truncated' => true];
        }

        return $this->core($field, $depth) ?? $this->probed($field, $depth) ?? $this->scalar($field);
    }

    /**
     * Core structural bases, matched by instanceof before any duck probe.
     *
     * @return array<string, mixed>|null
     */
    private function core(FieldInterface $field, int $depth): ?array {
        return match (true) {
            $field instanceof BaseRelationField => $this->relation($field),
            $field instanceof Matrix => $this->matrix($field, $depth),
            $field instanceof LinkField => $this->link($field),
            $field instanceof BaseOptionsField => $this->options($field),
            $field instanceof Table => $this->table($field),
            default => null,
        };
    }

    /**
     * Duck-typed capability probes for third-party fields; conventionally
     * named accessors only, no plugin class is ever imported or named.
     *
     * @return array<string, mixed>|null
     */
    private function probed(FieldInterface $field, int $depth): ?array {
        if (method_exists($field, 'getLinkTypes')) {
            return $this->links($field, $depth);
        }

        if ($field instanceof ElementContainerFieldInterface) {
            return $this->container($field, $depth);
        }

        return $this->objectProbe($field, $depth);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function objectProbe(FieldInterface $field, int $depth): ?array {
        if (!method_exists($field, 'getFieldLayout')) {
            return null;
        }

        $layout = $field->getFieldLayout();

        return $layout instanceof FieldLayout
            ? ['kind' => 'object', 'fields' => $this->ofLayout($layout, $depth - 1)]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function relation(BaseRelationField $field): array {
        return [
            'kind' => 'relation',
            'multiple' => true,
            'elementType' => $field::elementType(),
            'item' => $this->keys->keyShape($field::elementType()) ?? 'element id (no natural key for this type)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matrix(Matrix $field, int $depth): array {
        $blockTypes = [];
        foreach ($field->getEntryTypes() as $type) {
            $blockTypes[(string) $type->handle] = [
                'hasTitleField' => (bool) $type->hasTitleField,
                'fields' => $this->ofLayout($type->getFieldLayout(), $depth - 1),
            ];
        }

        return ['kind' => 'matrix', 'payload' => '{blockKey: {type, enabled, title?, fields}}', 'blockTypes' => $blockTypes];
    }

    /**
     * Core Link fields: reads the configured $types setting, not
     * getLinkTypes(), whose link-type objects carry no handle or layout and
     * need Craft services to instantiate.
     *
     * @return array<string, mixed>
     */
    private function link(LinkField $field): array {
        $types = [];
        foreach ($field->types as $typeId) {
            $target = LinkRef::TARGETS[$typeId] ?? null;
            $types[(string) $typeId] = $target === null ? null : $this->keys->keyShape($target);
        }

        return [
            'kind' => 'link',
            'payload' => 'single link object: {type, value, key?}; value is a URL or existing ref string; for element types pass key instead',
            'types' => $types,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(BaseOptionsField $field): array {
        $values = [];
        foreach ($field->options as $option) {
            if (is_array($option) && isset($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        return [
            'kind' => 'options',
            'multiple' => str_contains($field::phpType(), MultiOptionsFieldData::class),
            'allowedValues' => $values,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Table $field): array {
        $columns = [];
        foreach ($field->columns as $colId => $column) {
            $columns[(string) $colId] = [
                'handle' => $column['handle'] ?? null,
                'heading' => $column['heading'] ?? null,
                'type' => $column['type'] ?? null,
            ];
        }

        return ['kind' => 'table', 'payload' => 'list of row objects keyed by column handle (or colN)', 'columns' => $columns];
    }

    /**
     * @return array<string, mixed>
     */
    private function links(FieldInterface $field, int $depth): array {
        $linkTypes = [];
        foreach ($this->linkTypesOf($field) as $key => $linkType) {
            if (($linkType->enabled ?? true) !== true) {
                continue;
            }

            $handle = (string) ($linkType->handle ?? $key);
            $layout = method_exists($linkType, 'getFieldLayout') ? $linkType->getFieldLayout() : null;
            $linkTypes[$handle] = ['fields' => $this->ofLayout($layout, $depth - 1)];
        }

        return [
            'kind' => 'links',
            'payload' => 'list of link objects; identify each by handle or type; sub-fields per linkTypes',
            'linkTypes' => $linkTypes,
        ];
    }

    /**
     * PHPStan boundary: getLinkTypes() is a duck-typed probe method, not
     * declared on FieldInterface, so the call is routed through an
     * object-typed parameter instead of widening FieldInterface itself.
     *
     * @return array<int|string, object>
     */
    private function linkTypesOf(object $field): array {
        return $field->getLinkTypes();
    }

    /**
     * @return array<string, mixed>
     */
    private function container(ElementContainerFieldInterface $field, int $depth): array {
        $described = array_map(
            fn (object $provider): array => $this->ofLayout($provider->getFieldLayout(), $depth - 1),
            array_values($field->getFieldLayoutProviders()),
        );

        $shape = ['kind' => 'container', 'payload' => '{fields: {...}} or list of {fields: {...}}'];

        return count($described) === 1
            ? $shape + ['fields' => $described[0]]
            : $shape + ['variants' => $described];
    }

    /**
     * @return array<string, mixed>
     */
    private function scalar(FieldInterface $field): array {
        $valueType = $field::phpType();
        $shape = ['kind' => 'scalar', 'valueType' => $valueType];
        if (str_contains($valueType, 'DateTime')) {
            $shape['hint'] = 'ISO 8601 date-time string';
        }

        return $shape;
    }
}
