<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Closure;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;

/**
 * Natural keys for the core relation targets. The calling field supplies the
 * target element class, so identical key shapes (category vs tag) stay
 * unambiguous. Core ref resolution is used where core supports it; lookups
 * are injectable for Craft-free tests.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Keys {
    private const array SHAPES = [
        Entry::class => ['section', 'slug'],
        Category::class => ['group', 'slug'],
        Tag::class => ['group', 'slug'],
        User::class => ['username'],
        GlobalSet::class => ['handle'],
    ];

    public function __construct(
        private readonly ?AssetKey $assets = null,
        private readonly ?Closure $lookupId = null,
        private readonly ?Closure $lookupKey = null,
    ) {
    }

    public function supports(string $elementType): bool {
        return $elementType === Asset::class || isset(self::SHAPES[$elementType]);
    }

    public function idFor(string $elementType, array $key, ?string $site): ?int {
        if ($elementType === Asset::class) {
            return ($this->assets ?? new AssetKey())->idFor($key);
        }

        if (!$this->wellFormed($elementType, $key)) {
            return null;
        }

        if ($this->lookupId !== null) {
            return ($this->lookupId)($elementType, $key, $site);
        }

        return $this->queryId($elementType, $key, $site);
    }

    public function keyFor(string $elementType, int $id, ?string $site): ?array {
        if ($elementType === Asset::class) {
            return ($this->assets ?? new AssetKey())->keyFor($id);
        }

        if (!isset(self::SHAPES[$elementType])) {
            return null;
        }

        if ($this->lookupKey !== null) {
            return ($this->lookupKey)($elementType, $id, $site);
        }

        return $this->queryKey($elementType, $id, $site);
    }

    private function wellFormed(string $elementType, array $key): bool {
        $shape = self::SHAPES[$elementType] ?? null;
        if ($shape === null) {
            return false;
        }

        foreach ($shape as $part) {
            if (!is_string($key[$part] ?? null) || $key[$part] === '') {
                return false;
            }
        }

        return true;
    }

    private function queryId(string $elementType, array $key, ?string $site): ?int {
        $query = $elementType::find()->status(null);
        if ($site !== null) {
            $query->site($site);
        }

        match ($elementType) {
            Entry::class => $query->section($key['section'])->slug($key['slug']),
            Category::class => $query->group($key['group'])->slug($key['slug']),
            Tag::class => $query->group($key['group'])->slug($key['slug']),
            User::class => $query->username($key['username']),
            GlobalSet::class => $query->handle($key['handle']),
        };

        return $query->ids()[0] ?? null;
    }

    private function queryKey(string $elementType, int $id, ?string $site): ?array {
        $query = $elementType::find()->id($id)->status(null);
        if ($site !== null) {
            $query->site($site);
        }

        $element = $query->one();
        if ($element === null) {
            return null;
        }

        return match ($elementType) {
            Entry::class => $element->getSection() === null ? null : [
                'section' => $element->getSection()->handle,
                'slug' => (string) $element->slug,
            ],
            Category::class, Tag::class => [
                'group' => $element->getGroup()->handle,
                'slug' => (string) $element->slug,
            ],
            User::class => ['username' => (string) $element->username],
            GlobalSet::class => ['handle' => (string) $element->handle],
        };
    }
}
