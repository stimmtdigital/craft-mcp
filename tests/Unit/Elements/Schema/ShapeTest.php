<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Fixtures/CraftStub.php';
require_once dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

use craft\base\ElementContainerFieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Link as LinkField;
use craft\fields\Matrix;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\models\EntryType;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\schema\Shape;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

/**
 * A field implementing Craft's container interface with a configurable
 * provider list, so both the fillable-container path and the empty
 * (rich-text-style) fallthrough can be exercised without a plugin dependency.
 *
 * @param array<int, object> $providers
 */
function shapeContainerField(array $providers): ElementContainerFieldInterface {
    return new class ($providers) extends PlainText implements ElementContainerFieldInterface {
        /** @param array<int, object> $providers */
        public function __construct(private array $providers) {
            parent::__construct(['handle' => 'nested']);
        }

        public function getFieldLayoutProviders(): array {
            return $this->providers;
        }

        public function getUriFormatForElement(NestedElementInterface $element): ?string {
            return null;
        }

        public function getRouteForElement(NestedElementInterface $element): mixed {
            return null;
        }

        public function getSupportedSitesForElement(NestedElementInterface $element): array {
            return [];
        }

        public function canViewElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canSaveElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canDuplicateElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canDeleteElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canDeleteElementForSite(NestedElementInterface $element, User $user): ?bool {
            return null;
        }
    };
}

describe('Shape::of', function () {
    // Number::dbType() reads Craft::$app->getDb() during Field::init(), even
    // when the config carries no db-related setting; stub it the same way
    // RegistryTest does, and restore it so no other test file inherits it.
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        $db = new class () {
            public function getIsMysql(): bool {
                return false;
            }
        };

        Craft::$app = new class ($db) {
            public function __construct(private readonly object $db) {
            }

            public function getDb(): object {
                return $this->db;
            }
        };
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('describes scalar fields by their php value type', function () {
        expect((new Shape())->of(new PlainText(['handle' => 'body']))['kind'])->toBe('scalar')
            ->and((new Shape())->of(new Number(['handle' => 'n']))['valueType'])->toContain('int')
            ->and((new Shape())->of(new Lightswitch(['handle' => 'flag']))['valueType'])->toContain('bool');
    });

    it('hints the json format for date fields', function () {
        $out = (new Shape())->of(new Date(['handle' => 'when']));

        expect($out['kind'])->toBe('scalar')->and($out['hint'])->toContain('ISO 8601');
    });

    it('describes a relation field with its natural-key shape, not an id', function () {
        $out = (new Shape())->of(new Entries(['handle' => 'related']));

        expect($out['kind'])->toBe('relation')
            ->and($out['item'])->toBe(['section', 'slug'])
            ->and($out['elementType'])->toBe(Entry::class);
    });

    it('describes single and multi option fields with their allowed values', function () {
        $options = [
            ['label' => 'Small', 'value' => 'sm', 'default' => ''],
            ['label' => 'Large', 'value' => 'lg', 'default' => ''],
        ];
        $single = (new Shape())->of(new Dropdown(['handle' => 'size', 'options' => $options]));
        $multi = (new Shape())->of(new Checkboxes(['handle' => 'sizes', 'options' => $options]));

        expect($single['kind'])->toBe('options')
            ->and($single['multiple'])->toBeFalse()
            ->and($single['allowedValues'])->toBe(['sm', 'lg'])
            ->and($multi['multiple'])->toBeTrue();
    });

    it('describes the core link field by its configured types and key shapes', function () {
        $out = (new Shape())->of(new LinkField(['handle' => 'cta', 'types' => ['url', 'entry']]));

        expect($out['kind'])->toBe('link')
            ->and($out['types'])->toBe(['url' => null, 'entry' => ['section', 'slug']]);
    });

    it('describes a table field by its columns', function () {
        // addRowLabel is supplied so Table::init() skips its Craft::t() call,
        // which the stub above (Craft::$app only) does not cover.
        $table = new Table(['handle' => 'specs', 'addRowLabel' => 'Add a row', 'columns' => [
            'col1' => ['handle' => 'label', 'heading' => 'Label', 'type' => 'singleline'],
        ]]);

        $out = (new Shape())->of($table);

        expect($out['kind'])->toBe('table')
            ->and($out['columns']['col1']['handle'])->toBe('label');
    });

    it('expands matrix block types into per-type field shapes', function () {
        $layout = Layouts::with([new CustomField(new PlainText(['handle' => 'text']))]);
        $type = new class ($layout) extends EntryType {
            public function __construct(private readonly FieldLayout $blockLayout) {
                parent::__construct(['handle' => 'contentBlock', 'hasTitleField' => true]);
            }

            public function getFieldLayout(): FieldLayout {
                return $this->blockLayout;
            }
        };
        $matrix = new class ($type) extends Matrix {
            public function __construct(private readonly EntryType $only) {
                parent::__construct(['handle' => 'builder']);
            }

            public function getEntryTypes(): array {
                return [$this->only];
            }
        };

        $out = (new Shape())->of($matrix);

        expect($out['kind'])->toBe('matrix')
            ->and($out['blockTypes']['contentBlock']['hasTitleField'])->toBeTrue()
            ->and($out['blockTypes']['contentBlock']['fields']['fields'])->toHaveKey('text');
    });

    it('walks a layout into native attributes and recursed custom fields', function () {
        $dropdown = new CustomField(new Dropdown([
            'handle' => 'style',
            'options' => [['label' => 'A', 'value' => 'a', 'default' => '']],
        ]));

        $out = (new Shape())->ofLayout(Layouts::with([$dropdown]));

        expect($out['fields'])->toHaveKey('style')
            ->and($out['fields']['style']['input']['allowedValues'])->toBe(['a']);
    });

    it('describes a duck-typed link field generically without naming its class', function () {
        // A stand-in exposing getLinkTypes() the way Hyper does; proves the
        // probe recurses link-type layouts with no plugin dependency and that
        // a handle-less type falls back to its iteration key.
        $field = new class () extends PlainText {
            public function getLinkTypes(): array {
                $layout = Layouts::with([
                    new CustomField(new PlainText(['handle' => 'linkText'])),
                ]);

                return ['url' => new class ($layout) {
                    public bool $enabled = true;

                    public function __construct(private readonly FieldLayout $layout) {
                    }

                    public function getFieldLayout(): FieldLayout {
                        return $this->layout;
                    }
                }];
            }
        };
        $field->handle = 'buttons';

        $out = (new Shape())->of($field);

        expect($out['kind'])->toBe('links')
            ->and($out['linkTypes'])->toHaveKey('url')
            ->and($out['linkTypes']['url']['fields']['fields'])->toHaveKey('linkText')
            ->and($out['note'])->toContain('not natural keys')
            ->and($out['payload'])->toContain('custom sub-fields under a nested "fields" object');
    });

    it('describes a duck-typed layout-backed field as an object', function () {
        $field = new class () extends PlainText {
            public function getFieldLayout(): FieldLayout {
                return Layouts::with([new CustomField(new PlainText(['handle' => 'inner']))]);
            }
        };
        $field->handle = 'widget';

        $out = (new Shape())->of($field);

        expect($out['kind'])->toBe('object')
            ->and($out['fields']['fields'])->toHaveKey('inner')
            ->and($out['note'])->toContain('not natural keys');
    });

    it('hints a plain string for stringable field-data value types', function () {
        $out = (new Shape())->of(new Color(['handle' => 'accent']));

        expect($out['kind'])->toBe('scalar')
            ->and($out['valueType'])->toContain('ColorData')
            ->and($out['hint'])->toContain('string');
    });

    it('describes a container with providers as a fillable fields shape', function () {
        $provider = new class () {
            public function getFieldLayout(): FieldLayout {
                return Layouts::with([new CustomField(new PlainText(['handle' => 'inner']))]);
            }
        };

        $out = (new Shape())->of(shapeContainerField([$provider]));

        expect($out['kind'])->toBe('container')
            ->and($out['note'])->toContain('not natural keys')
            ->and($out['fields']['fields'])->toHaveKey('inner');
    });

    it('falls through to scalar when a container has no providers (rich-text style)', function () {
        $out = (new Shape())->of(shapeContainerField([]));

        expect($out['kind'])->toBe('scalar');
    });

    it('truncates at depth zero', function () {
        expect((new Shape())->of(new PlainText(['handle' => 'body']), 0))
            ->toBe(['kind' => 'nested', 'truncated' => true]);
    });

    it('degrades to an annotated scalar when introspection throws', function () {
        $field = new class () extends PlainText {
            public function getLinkTypes(): array {
                throw new RuntimeException('plugin broke');
            }
        };
        $field->handle = 'broken';

        $out = (new Shape())->of($field);

        expect($out['kind'])->toBe('scalar')
            ->and($out['note'])->toBe('structure introspection failed')
            ->and($out['error'])->toBe('plugin broke');
    });
});
