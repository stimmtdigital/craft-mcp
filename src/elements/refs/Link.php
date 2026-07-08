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
 * Core Link fields store element links as ref strings ({entry:123@1:url});
 * the core link types only recognize refs carrying the ':url' suffix. Read
 * adds a natural "key" next to the ref; write resolves the key back into a
 * core-recognizable ref, preserving the original @site/:attr suffix parts and
 * leaving the ref untouched when the key resolves to the same element.
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

        $key = $this->keys->keyFor($ref['target'], $ref['id'], $context->site);
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

        $handle = (string) ($value['type'] ?? '');
        $target = self::TARGETS[$handle] ?? null;
        $id = $target !== null ? $this->keys->idFor($target, $key, $context->site) : null;
        $ref = $this->parseRef($value['value'] ?? null);

        if ($id !== null) {
            if ($ref === null || $ref['target'] !== $target || $ref['id'] !== $id) {
                $value['value'] = $this->buildRef($handle, $id, $ref);
            }

            return $value;
        }

        if ($ref === null) {
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
     * @return array{target: class-string, id: int, site: string, attr: ?string}|null
     */
    private function parseRef(mixed $raw): ?array {
        if (!is_string($raw) || !preg_match('/^\{(\w+):(\d+)(@\d+)?(:\w+)?\}$/', $raw, $m)) {
            return null;
        }

        $target = self::TARGETS[$m[1]] ?? null;
        if ($target === null) {
            return null;
        }

        return [
            'target' => $target,
            'id' => (int) $m[2],
            'site' => $m[3] ?? '',
            'attr' => ($m[4] ?? '') === '' ? null : $m[4],
        ];
    }

    /**
     * Original @site and :attr suffix parts survive a rewrite; without an
     * original ref the canonical core format is emitted, ':url' included
     * (core's element link types anchor on that suffix).
     */
    private function buildRef(string $handle, int $id, ?array $ref): string {
        return sprintf('{%s:%d%s%s}', $handle, $id, $ref['site'] ?? '', $ref['attr'] ?? ':url');
    }
}
