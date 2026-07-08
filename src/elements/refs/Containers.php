<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Closure;
use craft\base\FieldInterface;
use craft\fields\Addresses;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use stimmt\craft\Mcp\elements\Context;

/**
 * One shared recursion over the nested-container fields (Matrix and
 * subclasses, ContentBlock, Addresses). Per-field translation is delegated
 * back to the Translator via the injected closure so it lives in one place;
 * the container field travels along so the Translator can resolve the
 * nested layout per container type.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Containers implements FieldTranslator {
    /**
     * @param Closure(FieldInterface, string, array, Context, bool): array $recurse
     */
    public function __construct(
        private Closure $recurse,
    ) {
    }

    public function handles(FieldInterface $field): bool {
        return $field instanceof Matrix
            || $field instanceof ContentBlock
            || $field instanceof Addresses;
    }

    public function toKeys(FieldInterface $field, mixed $value, Context $context): mixed {
        return $this->walk($field, $value, $context, toKeys: true);
    }

    public function toIds(FieldInterface $field, mixed $value, Context $context): mixed {
        return $this->walk($field, $value, $context, toKeys: false);
    }

    private function walk(FieldInterface $field, mixed $value, Context $context, bool $toKeys): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['fields']) && is_array($value['fields'])) {
            $value['fields'] = ($this->recurse)($field, '', $value['fields'], $context, $toKeys);

            return $value;
        }

        foreach ($value as $key => $block) {
            if (is_array($block) && isset($block['fields']) && is_array($block['fields'])) {
                $value[$key]['fields'] = ($this->recurse)($field, (string) ($block['type'] ?? ''), $block['fields'], $context, $toKeys);
            }
        }

        return $value;
    }
}
