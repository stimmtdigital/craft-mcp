<?php

declare(strict_types=1);

use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\Entries;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\schema\Describer;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

describe('Describer', function () {
    it('describes custom fields with layout-level overrides', function () {
        $body = new CustomField(new PlainText(['handle' => 'body', 'name' => 'Body', 'instructions' => 'Write here']));
        $body->required = true;

        // showUnpermittedSections bypasses the Craft::$app permission lookup in getInputSources(),
        // which isn't available in the unit test environment.
        $related = new CustomField(new Entries([
            'handle' => 'related',
            'name' => 'Related',
            'showUnpermittedSections' => true,
        ]));

        $fields = (new Describer())->describe(Layouts::with([$body, $related]));

        $byHandle = array_column($fields, null, 'handle');
        expect($byHandle)->toHaveKeys(['body', 'related'])
            ->and($byHandle['body']['required'])->toBeTrue()
            ->and($byHandle['body']['instructions'])->toBe('Write here')
            ->and($byHandle['body']['kind'])->toBe('plain')
            ->and($byHandle['related']['kind'])->toBe('relation')
            ->and($byHandle['related']['target']['elementType'])->toBe(craft\elements\Entry::class)
            ->and($byHandle['related']['target']['sources'])->toBe('*');
    });

    it('lists native layout fields', function () {
        $natives = (new Describer())->natives(Layouts::with([new TitleField()]));

        // TitleField has no per-layout label override, so name falls back to the raw ''
        // (the translated default label would need Craft services, unavailable here).
        expect($natives)->toHaveCount(1)
            ->and($natives[0]['attribute'])->toBe('title')
            ->and($natives[0]['name'])->toBe('')
            ->and($natives[0]['mandatory'])->toBeTrue();
    });

    it('returns empty for a null layout', function () {
        expect((new Describer())->describe(null))->toBe([])
            ->and((new Describer())->natives(null))->toBe([]);
    });
});
