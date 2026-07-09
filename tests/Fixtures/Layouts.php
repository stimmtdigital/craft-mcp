<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use Closure;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use ReflectionObject;
use stimmt\craft\Mcp\elements\refs\Keys;

/**
 * Shared unit-test builders for the elements module.
 */
final class Layouts {
    /**
     * Builds a real FieldLayout whose private tab/element storage is set via
     * reflection, bypassing only setTabs()/setElements() (which require
     * Craft::$app services); layout traversal itself runs unmocked.
     */
    public static function with(array $elements): FieldLayout {
        $layout = new FieldLayout();
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);

        (new ReflectionObject($tab))->getProperty('_elements')->setValue($tab, $elements);
        (new ReflectionObject($layout))->getProperty('_tabs')->setValue($layout, [$tab]);

        return $layout;
    }

    /**
     * Keys with injected lookups, so no Craft element queries run. The
     * defaults resolve the canonical fixture key {section: pages, slug: about}
     * to id 7 and back.
     */
    public static function keysWith(?Closure $lookupId = null, ?Closure $lookupKey = null): Keys {
        return new Keys(
            lookupId: $lookupId ?? static fn (string $type, array $key, ?string $site): ?int => $key === ['section' => 'pages', 'slug' => 'about'] ? 7 : null,
            lookupKey: $lookupKey ?? static fn (string $type, int $id, ?string $site): ?array => $id === 7 ? ['section' => 'pages', 'slug' => 'about'] : null,
        );
    }
}
