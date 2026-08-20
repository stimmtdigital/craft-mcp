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
        return $this->numbered($this->walk($field, $value, $context, toKeys: true));
    }

    public function toIds(FieldInterface $field, mixed $value, Context $context): mixed {
        return $this->walk($field, $this->ordered($value), $context, toKeys: false);
    }

    /**
     * Number the blocks in field order on the way out. Order cannot survive the
     * wire on its own: blocks are keyed by their numeric entry id, and a JSON
     * object whose keys look like integers is re-ordered ascending by most
     * clients (JavaScript does it by specification), so the payload an agent
     * receives is in id order rather than page order. An explicit position
     * keeps the real order readable no matter what the transport did to the
     * keys, and move_nested_entry is what changes it.
     */
    private function numbered(mixed $value): mixed {
        if (!$this->isBlockList($value)) {
            return $value;
        }

        $position = 0;
        foreach ($value as $key => $block) {
            if (is_array($block)) {
                $value[$key]['position'] = ++$position;
            }
        }

        return $value;
    }

    /**
     * Restore the caller's intended order on the way in, because Craft renumbers
     * sortOrder from the order of the value it is given: a payload whose keys
     * were re-ordered in transit would otherwise silently reshuffle the field.
     * Positions are advisory: a payload that omits them entirely keeps the
     * order it arrived in, and a payload that carries them on only some blocks
     * orders those and appends the rest. The key never reaches Craft either
     * way.
     */
    private function ordered(mixed $value): mixed {
        if (!$this->isBlockList($value)) {
            return $value;
        }

        // Positioned blocks lead, in the order the caller asked for; anything
        // without a position follows in the order it arrived.
        //
        // The all-or-nothing rule this replaces skipped sorting entirely when
        // one block lacked a position, which is exactly the payload an agent
        // produces when it reads a field, keeps the positions it was given and
        // adds a new block. The field was then silently reshuffled into
        // whatever order the transport left it in, which is the accident this
        // whole mechanism exists to prevent.
        $positioned = [];
        $unpositioned = [];
        foreach ($value as $key => $block) {
            if (is_array($block) && isset($block['position'])) {
                $positioned[$key] = $block;

                continue;
            }

            $unpositioned[$key] = $block;
        }

        uasort($positioned, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        $value = $positioned + $unpositioned;

        foreach ($value as $key => $block) {
            if (is_array($block)) {
                unset($value[$key]['position']);
            }
        }

        return $value;
    }

    /**
     * A list of blocks keyed by id, as opposed to a single container's own
     * {fields: ...} value, which carries no ordering of its own.
     */
    private function isBlockList(mixed $value): bool {
        return is_array($value) && !isset($value['fields']);
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
