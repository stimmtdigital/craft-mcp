<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use craft\base\ElementContainerFieldInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\enums\PropagationMethod;
use craft\fields\Matrix;
use stimmt\craft\Mcp\elements\LayoutFields;

/**
 * The multi-site answer a caller actually asks for before writing: if I set
 * this field on one site, does another site change with it?
 *
 * For a plain field that is its own translation method. For a container field
 * it is not, and the raw method answers a narrower question than the one
 * asked: a Matrix reports translationMethod "site" about which blocks belong
 * to which site, while the content inside those blocks is only as per-site as
 * the block's own sub-fields are. A Matrix whose blocks propagate to every
 * site and whose sub-fields are untranslated shares every value with every
 * site, and reporting it as per-site is how an agent overwrites another
 * site's content believing it did not.
 *
 * So perSite is the whole subtree's answer, false as soon as any nested value
 * is shared, and `propagation` reports the Matrix setting that decides it.
 * `method` stays the field's own Craft setting, unchanged.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Translation {
    /**
     * @return array{method: string, propagation?: string, perSite: bool}
     */
    public function of(FieldInterface $field): array {
        $propagation = $field instanceof Matrix
            ? ['propagation' => $field->propagationMethod->value]
            : [];

        return [
            'method' => $field->translationMethod,
            ...$propagation,
            'perSite' => $this->perSite($field, []),
        ];
    }

    /**
     * @param array<string, true> $seen containers already on the stack, since
     *                                  a Matrix can nest itself and a cycle
     *                                  has no further sharing to discover
     */
    private function perSite(FieldInterface $field, array $seen): bool {
        if ($field instanceof Matrix) {
            return $this->matrixPerSite($field, $seen);
        }

        // Anything short of per-site reads as false, because false is the
        // answer that makes the caller careful.
        $own = $field->translationMethod === Field::TRANSLATION_METHOD_SITE;

        if (!$field instanceof ElementContainerFieldInterface) {
            return $own;
        }

        // Every other container (a rich-text field that can hold entries, a
        // content block, an addresses field) stores a value of its own AND
        // carries nested content. Both have to be per-site for the answer to
        // be yes; the nested walk alone would call a shared field per-site.
        return $own && $this->nestedPerSite($field, $seen);
    }

    /**
     * @param array<string, true> $seen
     */
    private function matrixPerSite(Matrix $field, array $seen): bool {
        return match ($field->propagationMethod) {
            // Every site keeps its own blocks, so nothing inside one can leak.
            PropagationMethod::None => true,
            // The same blocks stand on every site: the sub-fields decide.
            PropagationMethod::All => $this->nestedPerSite($field, $seen),
            // Blocks are shared with at least the rest of a language, site
            // group, or custom propagation key.
            default => false,
        };
    }

    /**
     * @param array<string, true> $seen
     */
    private function nestedPerSite(ElementContainerFieldInterface $field, array $seen): bool {
        $key = (string) ($field->uid ?? $field->handle);
        if (isset($seen[$key])) {
            return true;
        }

        $seen[$key] = true;

        return array_all(
            $this->nested($field),
            fn (FieldInterface $sub): bool => $this->perSite($sub, $seen),
        );
    }

    /**
     * @return list<FieldInterface>
     */
    private function nested(ElementContainerFieldInterface $field): array {
        $fields = [];
        foreach ($field->getFieldLayoutProviders() as $provider) {
            $fields = [...$fields, ...array_values(LayoutFields::of($provider->getFieldLayout()))];
        }

        return $fields;
    }
}
