<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fields\Link as LinkField;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\Warning;

/**
 * Core Link fields store element links as numeric-id ref strings
 * ({entry:123@1}) and their normalizer only accepts numeric ids. Read adds a
 * natural "key" next to the ref; write resolves the key back into the ref.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Link implements FieldTranslator {
    private const array TARGETS = [
        'entry' => Entry::class,
        'asset' => Asset::class,
        'category' => Category::class,
    ];

    public function __construct(
        private Keys $keys,
    ) {
    }

    public function handles(FieldInterface $field): bool {
        return $field instanceof LinkField;
    }

    public function toKeys(FieldInterface $field, mixed $value, Context $context): mixed {
        if (!is_array($value)) {
            return $value;
        }

        $ref = $this->parseRef($value['value'] ?? null);
        if ($ref === null) {
            return $value;
        }

        [$target, $id] = $ref;
        $key = $this->keys->keyFor($target, $id, $context->site);
        if ($key !== null) {
            $value['key'] = $key;
        }

        return $value;
    }

    public function toIds(FieldInterface $field, mixed $value, Context $context): mixed {
        if (!is_array($value) || !is_array($value['key'] ?? null)) {
            return $value;
        }

        $key = $value['key'];
        unset($value['key']);

        $target = self::TARGETS[$value['type'] ?? ''] ?? null;
        $id = $target !== null ? $this->keys->idFor($target, $key, $context->site) : null;

        if ($id !== null) {
            $handle = array_search($target, self::TARGETS, true);
            $value['value'] = sprintf('{%s:%d}', $handle, $id);

            return $value;
        }

        if ($this->parseRef($value['value'] ?? null) === null) {
            $context->warn(new Warning(
                (string) $field->handle,
                (string) $field->handle,
                $key,
                'Link key does not resolve',
            ));
        }

        return $value;
    }

    /**
     * @return array{0: class-string, 1: int}|null [target element class, id]
     */
    private function parseRef(mixed $raw): ?array {
        if (!is_string($raw) || !preg_match('/^\{(\w+):(\d+)(?:@\d+)?/', $raw, $m)) {
            return null;
        }

        $target = self::TARGETS[$m[1]] ?? null;

        return $target === null ? null : [$target, (int) $m[2]];
    }
}
