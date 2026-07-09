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

    private function attributes(ElementInterface $element): array {
        $attributes = [
            'id' => $element->id,
            'canonicalId' => $element->getCanonicalId(),
            'title' => $element->title,
            'slug' => $element->slug,
            'status' => $element->getStatus(),
            'state' => $element->getIsDraft() ? WriteMode::Draft->value : WriteMode::Live->value,
            'draftId' => $element->draftId ?? null,
            'siteId' => $element->siteId,
            'siteHandle' => $element->getSite()->handle,
            'dateCreated' => $element->dateCreated?->format('Y-m-d H:i:s'),
            'dateUpdated' => $element->dateUpdated?->format('Y-m-d H:i:s'),
            'url' => $element->getUrl(),
            'cpEditUrl' => $element->getCpEditUrl(),
        ];

        if ($element instanceof Entry) {
            $attributes['sectionHandle'] = $element->getSection()?->handle;
            $attributes['typeHandle'] = $element->getType()->handle;
            $attributes['authorId'] = $element->getAuthorId();
            $attributes['postDate'] = $element->postDate?->format('Y-m-d H:i:s');
            $attributes['expiryDate'] = $element->expiryDate?->format('Y-m-d H:i:s');
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
