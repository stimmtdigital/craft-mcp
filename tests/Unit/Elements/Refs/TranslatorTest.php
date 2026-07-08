<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixtures/CraftStub.php';

use craft\fields\Entries;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Registry;
use stimmt\craft\Mcp\elements\refs\Translator;

function fixtureFields(): array {
    return [
        'body' => new PlainText(['handle' => 'body']),
        'related' => new Entries(['handle' => 'related']),
    ];
}

function fixtureKeys(): Keys {
    return new Keys(
        lookupId: fn (string $type, array $key, ?string $site): ?int => $key === ['section' => 'pages', 'slug' => 'about'] ? 7 : null,
        lookupKey: fn (string $type, int $id, ?string $site): ?array => $id === 7 ? ['section' => 'pages', 'slug' => 'about'] : null,
    );
}

describe('Translator', function () {
    it('translates only fields a translator handles, passing the rest through', function () {
        $translator = Translator::withDefaults(fixtureKeys());
        $context = new Context('en');

        $out = $translator->toKeys(fixtureFields(), [
            'body' => 'plain text stays',
            'related' => [7],
        ], $context);

        expect($out['body'])->toBe('plain text stays')
            ->and($out['related'])->toBe([['section' => 'pages', 'slug' => 'about']]);
    });

    it('translates keys back to ids on write', function () {
        $translator = Translator::withDefaults(fixtureKeys());

        $out = $translator->toIds(fixtureFields(), [
            'related' => [['section' => 'pages', 'slug' => 'about']],
        ], new Context('en'));

        expect($out['related'])->toBe([7]);
    });

    it('leaves values for handles missing from the layout untouched', function () {
        $translator = Translator::withDefaults(fixtureKeys());

        $out = $translator->toIds(fixtureFields(), ['ghost' => [1, 2]], new Context());

        expect($out['ghost'])->toBe([1, 2]);
    });

    it('lets an event-registered translator override a built-in', function () {
        $registry = new Registry();
        $translator = Translator::withDefaults(fixtureKeys(), $registry);
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

        expect($translator->toKeys(fixtureFields(), ['related' => [7]], new Context())['related'])->toBe('custom');
    });
});
