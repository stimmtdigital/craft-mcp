<?php

declare(strict_types=1);

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use stimmt\craft\Mcp\elements\LayoutFields;

/**
 * Builds a real FieldLayout whose private tab/element storage is set via
 * reflection, bypassing only setTabs()/setElements() (which require
 * Craft::$app services); getCustomFields() itself runs unmocked.
 */
function layoutWith(array $elements): FieldLayout {
    $layout = new FieldLayout();
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);

    $elementsProperty = (new ReflectionObject($tab))->getProperty('_elements');
    $elementsProperty->setValue($tab, $elements);

    $tabsProperty = (new ReflectionObject($layout))->getProperty('_tabs');
    $tabsProperty->setValue($layout, [$tab]);

    return $layout;
}

describe('LayoutFields', function () {
    it('returns effective clones keyed by effective handle through the real layout traversal', function () {
        $field = new PlainText(['handle' => 'body', 'name' => 'Body']);
        $plain = new CustomField($field);

        $overridden = new CustomField($field);
        $overridden->handle = 'summary';
        $overridden->setField($field);

        $fields = LayoutFields::of(layoutWith([$plain, $overridden]));

        expect(array_keys($fields))->toBe(['body', 'summary'])
            ->and($fields['summary']->handle)->toBe('summary')
            ->and($fields['summary'])->not->toBe($fields['body']);
    });

    it('returns empty for a null layout', function () {
        expect(LayoutFields::of(null))->toBe([]);
    });
});
