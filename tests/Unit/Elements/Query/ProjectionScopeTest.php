<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Fixtures/CraftStub.php';
require_once dirname(__DIR__, 3) . '/Fixtures/CustomFieldBehaviorStub.php';
require_once dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\query\Projection;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

/**
 * An entry whose field layout is fixed at construction, standing in for one
 * row of a mixed result set. A Matrix block is an entry too, so the rows of
 * one list_entries call do not share a field layout.
 */
function projectionEntry(FieldLayout $layout): Entry {
    return new class ($layout) extends Entry {
        public function __construct(private readonly FieldLayout $stubLayout) {
            parent::__construct(['id' => 1, 'siteId' => 1]);
        }

        public function getFieldLayout(): ?FieldLayout {
            return $this->stubLayout;
        }
    };
}

describe('Projection field whitelist', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;

        // The install knows both handles; this row's own layout carries only one.
        Craft::$app = new class () {
            public function getFields(): object {
                return new class () {
                    public function getFieldByHandle(string $handle): ?FieldInterface {
                        return in_array($handle, ['body', 'contentBuilder'], true)
                            ? new PlainText(['handle' => $handle])
                            : null;
                    }
                };
            }
        };

        $this->projection = (new ReflectionClass(Projection::class))->newInstanceWithoutConstructor();
        $this->entry = projectionEntry(Layouts::with([new CustomField(new PlainText(['handle' => 'body']))]));
        $this->split = fn (array $fields): array => (new ReflectionMethod(Projection::class, 'split'))
            ->invoke($this->projection, $this->entry, $fields);
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('splits attributes from the field handles this row carries', function () {
        expect(($this->split)(['slug', 'body']))->toBe([['slug'], ['body']]);
    });

    // The description promises id and title in every row, so naming one is
    // redundant rather than an error.
    it('accepts the names every row carries anyway', function () {
        expect(($this->split)(['id', 'title']))->toBe([[], []]);
    });

    // The defect: the whitelist came from whichever entry sorted first, so a
    // real page field was refused as unknown on an unscoped call.
    it('accepts a field this row lacks but the install has', function () {
        expect(($this->split)(['contentBuilder']))->toBe([[], []]);
    });

    it('refuses a name no field answers to, pointing at the discovery tools', function () {
        try {
            ($this->split)(['zzz']);
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())
                ->toContain("'zzz'")
                ->toContain('list_fields')
                ->toContain('describe_entry_schema');

            return;
        }

        $this->fail('Expected an InvalidArgumentException for an unknown projection field');
    });
});
