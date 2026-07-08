<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\fields\Link as LinkField;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Link;

function linkKeys(): Keys {
    return new Keys(
        lookupId: fn (string $type, array $key, ?string $site): ?int => ($type === Entry::class && $key === ['section' => 'pages', 'slug' => 'about']) ? 7 : null,
        lookupKey: fn (string $type, int $id, ?string $site): ?array => ($type === Entry::class && $id === 7) ? ['section' => 'pages', 'slug' => 'about'] : null,
    );
}

describe('Link', function () {
    it('handles only the core Link field', function () {
        $link = new Link(linkKeys());

        expect($link->handles(new LinkField()))->toBeTrue()
            ->and($link->handles(new craft\fields\PlainText()))->toBeFalse();
    });

    it('adds a natural key next to an element ref value on read', function () {
        $link = new Link(linkKeys());

        $out = $link->toKeys(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:7@1}',
            'type' => 'entry',
            'label' => 'Read more',
        ], new Context('en'));

        expect($out['key'])->toBe(['section' => 'pages', 'slug' => 'about'])
            ->and($out['value'])->toBe('{entry:7@1}')
            ->and($out['label'])->toBe('Read more');
    });

    it('leaves non-element link values untouched on read', function () {
        $link = new Link(linkKeys());
        $value = ['value' => 'https://example.com', 'type' => 'url'];

        expect($link->toKeys(new LinkField(), $value, new Context()))->toBe($value);
    });

    it('rewrites value from the key on write and strips the key', function () {
        $link = new Link(linkKeys());

        $out = $link->toIds(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:999}',
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'about'],
        ], new Context('en'));

        expect($out['value'])->toBe('{entry:7}')
            ->and($out)->not->toHaveKey('key');
    });

    it('warns when the key does not resolve and no id ref exists', function () {
        $link = new Link(linkKeys());
        $context = new Context('en');

        $out = $link->toIds(new LinkField(['handle' => 'cta']), [
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'missing'],
        ], $context);

        expect($out)->not->toHaveKey('key')
            ->and($context->warnings())->toHaveCount(1)
            ->and($context->warnings()[0]->field)->toBe('cta');
    });
});
