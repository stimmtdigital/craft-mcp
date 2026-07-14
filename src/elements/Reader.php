<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\fields\Matrix;
use stimmt\craft\Mcp\elements\refs\Translator;

/**
 * Element to agent payload: native attributes plus serialized custom fields
 * with relation ids swapped for natural keys. Container values are read with
 * status(null) so disabled blocks survive round trips.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Reader {
    private const array COMMON_ATTRIBUTES = [
        'id', 'canonicalId', 'title', 'slug', 'status', 'state', 'draftId',
        'siteId', 'siteHandle', 'dateCreated', 'dateUpdated', 'url', 'cpEditUrl',
    ];

    private const array ENTRY_ATTRIBUTES = ['sectionHandle', 'typeHandle', 'authorId', 'postDate', 'expiryDate'];

    public function __construct(
        private Translator $translator,
    ) {
    }

    public function read(ElementInterface $element, ?string $site = null): array {
        $context = new Context($site ?? $element->getSite()->handle);
        $fields = LayoutFields::of($element->getFieldLayout());

        $payload = $this->attributes($element);
        $payload['fields'] = $this->translateFields($fields, $this->serializedFields($element, $fields), $context);
        $payload['warnings'] = array_map(static fn (Warning $w): array => $w->toArray(), $context->warnings());

        return $payload;
    }

    /**
     * @param array<string, FieldInterface> $fields
     */
    public function translateFields(array $fields, array $values, Context $context): array {
        return $this->translator->toKeys($fields, $values, $context);
    }

    /**
     * Payload-format values for a subset of an element's fields, for slim
     * projections. Unknown handles are the caller's problem: filter first.
     *
     * @param string[] $handles
     */
    public function readFields(ElementInterface $element, array $handles, ?string $site = null): array {
        $context = new Context($site ?? $element->getSite()->handle);
        $fields = array_intersect_key(LayoutFields::of($element->getFieldLayout()), array_flip($handles));

        return $this->translateFields($fields, $this->serializedFields($element, $fields), $context);
    }

    private function attributes(ElementInterface $element): array {
        $names = $element instanceof Entry
            ? [...self::COMMON_ATTRIBUTES, ...self::ENTRY_ATTRIBUTES]
            : self::COMMON_ATTRIBUTES;

        $attributes = [];
        foreach ($names as $name) {
            $attributes[$name] = Attributes::value($element, $name);
        }

        return $attributes;
    }

    /**
     * @param array<string, FieldInterface> $fields
     */
    private function serializedFields(ElementInterface $element, array $fields): array {
        $values = [];
        foreach ($fields as $handle => $field) {
            $value = $element->getFieldValue($handle);
            if ($field instanceof Matrix && $value instanceof EntryQuery) {
                $value = (clone $value)->status(null);
            }

            $values[$handle] = $field->serializeValue($value, $element);
        }

        return $values;
    }
}
