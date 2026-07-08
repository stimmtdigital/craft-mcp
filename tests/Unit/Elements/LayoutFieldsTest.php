<?php

declare(strict_types=1);

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\LayoutFields;

class MockFieldLayout extends FieldLayout {
    private array $customFields;

    public function __construct(array $customFields = []) {
        parent::__construct();
        $this->customFields = $customFields;
    }

    public function getCustomFields(): array {
        return $this->customFields;
    }
}

describe('LayoutFields', function () {
    it('returns effective clones keyed by effective handle', function () {
        $field = new PlainText(['handle' => 'body', 'name' => 'Body']);
        $plain = new CustomField($field);
        $plain->handle = 'body';

        $overridden = new CustomField($field);
        $overridden->handle = 'summary';
        $overridden->setField($field);

        $layout = new MockFieldLayout([$plain, $overridden]);
        $fields = LayoutFields::of($layout);

        expect(array_keys($fields))->toBe(['body', 'summary'])
            ->and($fields['summary']->handle)->toBe('summary')
            ->and($fields['summary'])->not->toBe($fields['body']);
    });

    it('returns empty for a null layout', function () {
        expect(LayoutFields::of(null))->toBe([]);
    });
});
