<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;

/**
 * Builds a real FieldLayout with a single relation field, bypassing
 * setTabs()/setElements() (which require Craft::$app services) via
 * reflection, matching LayoutFieldsTest's approach.
 */
function writerLayoutWithRelated(): FieldLayout {
    $layout = new FieldLayout();
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);

    $elementsProperty = (new ReflectionObject($tab))->getProperty('_elements');
    $elementsProperty->setValue($tab, [new CustomField(new Entries(['handle' => 'related']))]);

    $tabsProperty = (new ReflectionObject($layout))->getProperty('_tabs');
    $tabsProperty->setValue($layout, [$tab]);

    return $layout;
}

describe('Writer', function () {
    it('prepares payload fields into id-resolved values', function () {
        $writer = new Writer(Translator::withDefaults(new Keys(
            lookupId: fn (string $type, array $key, ?string $site): ?int => $key === ['section' => 'pages', 'slug' => 'about'] ? 7 : null,
        )));

        $context = new Context('en');
        $prepared = $writer->prepare(writerLayoutWithRelated(), ['related' => [['section' => 'pages', 'slug' => 'about']]], $context);

        expect($prepared['related'])->toBe([7])
            ->and($context->warnings())->toBe([]);
    });

    it('collects warnings for unresolvable keys and keeps the rest', function () {
        $writer = new Writer(Translator::withDefaults(new Keys(lookupId: fn (): ?int => null)));

        $context = new Context('en');
        $prepared = $writer->prepare(writerLayoutWithRelated(), ['related' => [['section' => 'x', 'slug' => 'y'], 42]], $context);

        expect($prepared['related'])->toBe([42])
            ->and($context->warnings())->toHaveCount(1);
    });
});
