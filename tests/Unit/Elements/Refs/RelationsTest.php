<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\fields\Entries;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Relations;

function relationKeys(): Keys {
    return new Keys(
        lookupId: fn (string $type, array $key, ?string $site): ?int => $key === ['section' => 'pages', 'slug' => 'about'] ? 7 : null,
        lookupKey: fn (string $type, int $id, ?string $site): ?array => $id === 7 ? ['section' => 'pages', 'slug' => 'about'] : null,
    );
}

describe('Relations', function () {
    it('handles relation fields', function () {
        $relations = new Relations(relationKeys());

        expect($relations->handles(new Entries()))->toBeTrue()
            ->and($relations->handles(new craft\fields\PlainText()))->toBeFalse();
    });

    it('translates ids to keys on read, keeping unresolvable ids raw', function () {
        $relations = new Relations(relationKeys());
        $field = new Entries(['handle' => 'related']);

        $out = $relations->toKeys($field, [7, 99], new Context('en'));

        expect($out)->toBe([['section' => 'pages', 'slug' => 'about'], 99]);
    });

    it('translates keys to ids on write, warning on unresolvable keys', function () {
        $relations = new Relations(relationKeys());
        $field = new Entries(['handle' => 'related']);
        $context = new Context('en');

        $out = $relations->toIds($field, [
            ['section' => 'pages', 'slug' => 'about'],
            ['section' => 'pages', 'slug' => 'missing'],
            42,
        ], $context);

        expect($out)->toBe([7, 42])
            ->and($context->warnings())->toHaveCount(1)
            ->and($context->warnings()[0]->field)->toBe('related')
            ->and($context->warnings()[0]->key)->toBe(['section' => 'pages', 'slug' => 'missing']);
    });

    it('passes non-array values through unchanged', function () {
        $relations = new Relations(relationKeys());

        expect($relations->toKeys(new Entries(), null, new Context()))->toBeNull()
            ->and($relations->toIds(new Entries(), null, new Context()))->toBeNull();
    });
});
