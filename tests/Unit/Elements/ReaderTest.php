<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use craft\fields\Entries;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Translator;

describe('Reader', function () {
    it('translates serialized fields through the translator', function () {
        $translator = Translator::withDefaults(new Keys(
            lookupKey: fn (string $type, int $id, ?string $site): ?array => $id === 7 ? ['section' => 'pages', 'slug' => 'about'] : null,
        ));
        $reader = new Reader($translator);

        $fields = [
            'body' => new PlainText(['handle' => 'body']),
            'related' => new Entries(['handle' => 'related']),
        ];

        $out = $reader->translateFields($fields, ['body' => 'hi', 'related' => [7]], new Context('en'));

        expect($out['body'])->toBe('hi')
            ->and($out['related'])->toBe([['section' => 'pages', 'slug' => 'about']]);
    });
});
