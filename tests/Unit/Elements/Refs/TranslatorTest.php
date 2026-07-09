<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixtures/CraftStub.php';

use craft\fieldlayoutelements\CustomField;
use craft\fields\Addresses;
use craft\fields\ContentBlock;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Registry;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

describe('Translator', function () {
    beforeEach(function () {
        $this->fields = [
            'body' => new PlainText(['handle' => 'body']),
            'related' => new Entries(['handle' => 'related']),
        ];
    });

    it('translates only fields a translator handles, passing the rest through', function () {
        $translator = Translator::withDefaults(Layouts::keysWith());
        $context = new Context('en');

        $out = $translator->toKeys($this->fields, [
            'body' => 'plain text stays',
            'related' => [7],
        ], $context);

        expect($out['body'])->toBe('plain text stays')
            ->and($out['related'])->toBe([['section' => 'pages', 'slug' => 'about']]);
    });

    it('translates keys back to ids on write', function () {
        $translator = Translator::withDefaults(Layouts::keysWith());

        $out = $translator->toIds($this->fields, [
            'related' => [['section' => 'pages', 'slug' => 'about']],
        ], new Context('en'));

        expect($out['related'])->toBe([7]);
    });

    it('leaves values for handles missing from the layout untouched', function () {
        $translator = Translator::withDefaults(Layouts::keysWith());

        $out = $translator->toIds($this->fields, ['ghost' => [1, 2]], new Context());

        expect($out['ghost'])->toBe([1, 2]);
    });

    it('translates a ContentBlock value through the field\'s own layout', function () {
        $field = new ContentBlock(['handle' => 'seo']);
        $field->setFieldLayout(Layouts::with([new CustomField(new Entries(['handle' => 'related']))]));
        $translator = Translator::withDefaults(Layouts::keysWith());

        $keys = $translator->toKeys(['seo' => $field], ['seo' => ['fields' => ['related' => [7]]]], new Context('en'));
        $ids = $translator->toIds(['seo' => $field], ['seo' => ['fields' => ['related' => [['section' => 'pages', 'slug' => 'about']]]]], new Context('en'));

        expect($keys['seo']['fields']['related'])->toBe([['section' => 'pages', 'slug' => 'about']])
            ->and($ids['seo']['fields']['related'])->toBe([7]);
    });

    it('translates Addresses blocks through the shared Address layout', function () {
        $layout = Layouts::with([new CustomField(new Entries(['handle' => 'contact']))]);
        $original = Craft::$app;
        Craft::$app = new class ($layout) {
            public function __construct(private readonly FieldLayout $layout) {
            }

            public function getAddresses(): object {
                return new class ($this->layout) {
                    public function __construct(private readonly FieldLayout $layout) {
                    }

                    public function getFieldLayout(): FieldLayout {
                        return $this->layout;
                    }
                };
            }
        };

        try {
            $translator = Translator::withDefaults(Layouts::keysWith());

            $out = $translator->toIds(
                ['addresses' => new Addresses(['handle' => 'addresses'])],
                ['addresses' => ['1' => ['fields' => ['contact' => [['section' => 'pages', 'slug' => 'about']]]]]],
                new Context('en'),
            );

            expect($out['addresses']['1']['fields']['contact'])->toBe([7]);
        } finally {
            Craft::$app = $original;
        }
    });

    it('passes container values through unchanged when no layout resolves', function () {
        $translator = Translator::withDefaults(Layouts::keysWith());

        // A {fields:...} value on a Matrix recurses with an empty type handle,
        // which resolves no layout; the values must survive untouched.
        $out = $translator->toKeys(
            ['content' => new Matrix(['handle' => 'content'])],
            ['content' => ['fields' => ['related' => [7]]]],
            new Context('en'),
        );

        expect($out['content']['fields']['related'])->toBe([7]);
    });

    it('lets an event-registered translator override a built-in', function () {
        $registry = new Registry();
        $translator = Translator::withDefaults(Layouts::keysWith(), $registry);
        $registry->register(new class () implements stimmt\craft\Mcp\elements\refs\FieldTranslator {
            public function handles(craft\base\FieldInterface $field): bool {
                return $field instanceof Entries;
            }

            public function toKeys(craft\base\FieldInterface $field, mixed $value, Context $context): mixed {
                return 'custom';
            }

            public function toIds(craft\base\FieldInterface $field, mixed $value, Context $context): mixed {
                return 'custom';
            }
        });

        expect($translator->toKeys($this->fields, ['related' => [7]], new Context())['related'])->toBe('custom');
    });
});
