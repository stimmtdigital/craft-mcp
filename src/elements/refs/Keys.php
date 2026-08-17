<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Closure;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQuery;
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
final readonly class Keys {
    private const array SHAPES = [
        Entry::class => ['section', 'slug'],
        Category::class => ['group', 'slug'],
        Tag::class => ['group', 'slug'],
        User::class => ['username'],
        GlobalSet::class => ['handle'],
    ];

    public function __construct(
        private ?AssetKey $assets = null,
        private ?Closure $lookupId = null,
        private ?Closure $lookupKey = null,
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

    /**
     * The natural-key field list for a target element class, or null when the
     * type has no natural key. Single source of truth shared with the schema
     * describer so a documented shape never drifts from idFor()/keyFor().
     *
     * @return string[]|null
     */
    public function keyShape(string $elementType): ?array {
        if ($elementType === Asset::class) {
            return ['volume', 'path?', 'filename'];
        }

        return self::SHAPES[$elementType] ?? null;
    }

    private function wellFormed(string $elementType, array $key): bool {
        $shape = self::SHAPES[$elementType] ?? null;
        if ($shape === null) {
            return false;
        }

        return array_all($shape, fn ($part) => is_string($key[$part] ?? null) && $key[$part] !== '');
    }

    /**
     * A query that can see unpublished drafts, and only those.
     *
     * WHY: writes land as drafts by default, so the ordinary session is to
     * create an entry and then relate to it from the next one. With Craft's
     * default (`drafts` false, which appends `elements.draftId IS NULL`) that
     * relation could never resolve, and the agent got "No entry matches this
     * key" for an entry it had just created.
     *
     * Only UNPUBLISHED drafts, though, and the distinction is not cosmetic.
     * `Drafts::applyDraft()` publishes an unpublished draft in place, keeping
     * the element id, so storing that id in a relation is safe. A DERIVATIVE
     * draft is hard deleted on publish once its canonical has been updated, so
     * its id would take the relation with it. `draftOf(false)` is Craft's own
     * spelling for the first group: it adds `drafts.canonicalId IS NULL` over a
     * LEFT JOIN, which a canonical element satisfies too. Derivative drafts
     * also share their canonical's slug, so admitting them would make the
     * result order-dependent as well as unsafe.
     */
    private function resolvable(ElementQuery $query, ?string $site): void {
        $query->status(null)->drafts(null)->draftOf(false);
        if ($site !== null) {
            $query->site($site);
        }
    }

    private function queryId(string $elementType, array $key, ?string $site): ?int {
        $query = $elementType::find();
        $this->resolvable($query, $site);

        $constrained = match ($elementType) {
            Entry::class => $query->section($key['section'])->slug($key['slug']),
            Category::class => $query->group($key['group'])->slug($key['slug']),
            Tag::class => $query->group($key['group'])->slug($key['slug']),
            User::class => $query->username($key['username']),
            GlobalSet::class => $query->handle($key['handle']),
            default => null,
        };

        // Never run the query unconstrained: an unsupported type must miss.
        if ($constrained === null) {
            return null;
        }

        return $constrained->ids()[0] ?? null;
    }

    private function queryKey(string $elementType, int $id, ?string $site): ?array {
        // The read half of the same defect: an entry legitimately relating to
        // an unpublished draft read back as a bare integer id, because this
        // lookup could not see the draft and Relations::toKeys() falls through
        // to the raw id when no key resolves.
        $query = $elementType::find();
        $this->resolvable($query, $site);

        $element = $query->id($id)->one();
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
            default => null,
        };
    }
}
