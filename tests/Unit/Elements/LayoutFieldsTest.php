<?php

declare(strict_types=1);

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

describe('LayoutFields', function () {
    it('returns effective clones keyed by effective handle through the real layout traversal', function () {
        $field = new PlainText(['handle' => 'body', 'name' => 'Body']);
        $plain = new CustomField($field);

        $overridden = new CustomField($field);
        $overridden->handle = 'summary';
        $overridden->setField($field);

        $fields = LayoutFields::of(Layouts::with([$plain, $overridden]));

        expect(array_keys($fields))->toBe(['body', 'summary'])
            ->and($fields['summary']->handle)->toBe('summary')
            ->and($fields['summary'])->not->toBe($fields['body']);
    });

    it('returns empty for a null layout', function () {
        expect(LayoutFields::of(null))->toBe([]);
    });
});
