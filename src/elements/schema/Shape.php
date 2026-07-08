<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fields\Addresses;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\ContentBlock;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\Link as LinkField;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Link as LinkRef;
use Stringable;
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
    /**
     * Attached to shapes whose parent field the module's Writer does not
     * translate (Hyper-style link fields, generic layout-backed fields, and
     * third-party containers). Their nested values reach Craft raw, so an
     * agent must send stored IDs or ref strings there, not natural keys. The
     * translated core containers (Matrix, ContentBlock, Addresses) do resolve
     * natural keys and never carry this note.
     */
    private const string UNTRANSLATED_NOTE = 'nested values pass through unchanged: relation and link sub-fields here take stored IDs or ref strings, not natural keys';

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

        return $this->maybeContainer($field, $depth) ?? $this->objectProbe($field, $depth);
    }

    /**
     * A fillable-container shape, or null when the field is not a container
     * or has no nested layout to fill (so probed() falls through to object
     * or scalar).
     *
     * @return array<string, mixed>|null
     */
    private function maybeContainer(FieldInterface $field, int $depth): ?array {
        return $field instanceof ElementContainerFieldInterface
            ? $this->container($field, $depth)
            : null;
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
            ? ['kind' => 'object', 'note' => self::UNTRANSLATED_NOTE, 'fields' => $this->ofLayout($layout, $depth - 1)]
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
            'payload' => 'list of link objects; identify each by handle (or type); put native attributes (linkValue, linkText, ...) at the top level and custom sub-fields under a nested "fields" object keyed by handle',
            'note' => self::UNTRANSLATED_NOTE,
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
     * A container with no field-layout providers has no nested layout to
     * fill, so it is not a fields-container in the write sense (a rich-text
     * field that merely CAN embed entries lands here). Returning null lets
     * probed() fall through to the scalar description of its stored value.
     *
     * @return array<string, mixed>|null
     */
    private function container(ElementContainerFieldInterface $field, int $depth): ?array {
        $providers = array_values($field->getFieldLayoutProviders());
        if ($providers === []) {
            return null;
        }

        $described = array_map(
            fn (object $provider): array => $this->ofLayout($provider->getFieldLayout(), $depth - 1),
            $providers,
        );

        $shape = ['kind' => 'container', 'payload' => '{fields: {...}} or list of {fields: {...}}'];

        // Matrix is handled in core(); the only core containers the Writer
        // translates that reach here are ContentBlock and Addresses. Anything
        // else is a third-party container whose nested values pass through raw.
        if (!$field instanceof ContentBlock && !$field instanceof Addresses) {
            $shape['note'] = self::UNTRANSLATED_NOTE;
        }

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

        // A stringable value object (rich-text/HTML field data, colours, and
        // the like) serialises from a plain string, so tell the agent to send
        // one rather than leaving an opaque class name as the only signal.
        $class = ltrim(explode('|', $valueType)[0], '\\');
        if (str_contains($valueType, 'DateTime')) {
            $shape['hint'] = 'ISO 8601 date-time string';
        } elseif (str_contains($class, '\\') && is_a($class, Stringable::class, true)) {
            $shape['hint'] = 'pass a string value (rich-text fields expect HTML markup)';
        }

        return $shape;
    }
}
