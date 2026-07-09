<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\fields\Link as LinkField;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Link;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

// Mirrors BaseElementLinkType::supports()/elementQuery() in craftcms/cms:
// written refs must match /^\{entry:(\d+)(@(\d+))?:url\}$/ to resolve again.
const CORE_ENTRY_LINK_REF = '/^\{entry:(\d+)(@(\d+))?:url\}$/';

describe('Link', function () {
    beforeEach(function () {
        $this->link = new Link(Layouts::keysWith(
            lookupId: fn (string $type, array $key, ?string $site): ?int => ($type === Entry::class && $key === ['section' => 'pages', 'slug' => 'about']) ? 7 : null,
            lookupKey: fn (string $type, int $id, ?string $site): ?array => ($type === Entry::class && $id === 7) ? ['section' => 'pages', 'slug' => 'about'] : null,
        ));
    });

    it('handles only the core Link field', function () {
        expect($this->link->handles(new LinkField()))->toBeTrue()
            ->and($this->link->handles(new craft\fields\PlainText()))->toBeFalse();
    });

    it('adds a natural key next to an element ref value on read', function () {
        $out = $this->link->toKeys(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:7@1:url}',
            'type' => 'entry',
            'label' => 'Read more',
        ], new Context('en'));

        expect($out['key'])->toBe(['section' => 'pages', 'slug' => 'about'])
            ->and($out['value'])->toBe('{entry:7@1:url}')
            ->and($out['label'])->toBe('Read more');
    });

    it('leaves non-element link values untouched on read', function () {
        $value = ['value' => 'https://example.com', 'type' => 'url'];

        expect($this->link->toKeys(new LinkField(), $value, new Context()))->toBe($value);
    });

    it('rewrites a stale ref from the key in the core-recognizable format', function () {
        $out = $this->link->toIds(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:999:url}',
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'about'],
        ], new Context('en'));

        expect($out['value'])->toBe('{entry:7:url}')
            ->and($out['value'])->toMatch(CORE_ENTRY_LINK_REF)
            ->and($out)->not->toHaveKey('key');
    });

    it('preserves the original @site suffix when rewriting', function () {
        $out = $this->link->toIds(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:999@2:url}',
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'about'],
        ], new Context('en'));

        expect($out['value'])->toBe('{entry:7@2:url}')
            ->and($out['value'])->toMatch(CORE_ENTRY_LINK_REF);
    });

    it('emits the canonical :url format for a key without any existing ref', function () {
        $out = $this->link->toIds(new LinkField(['handle' => 'cta']), [
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'about'],
        ], new Context('en'));

        expect($out['value'])->toBe('{entry:7:url}')
            ->and($out['value'])->toMatch(CORE_ENTRY_LINK_REF);
    });

    it('leaves the ref byte-identical when the key resolves to the same element', function () {
        $out = $this->link->toIds(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:7@1:url}',
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'about'],
        ], new Context('en'));

        expect($out['value'])->toBe('{entry:7@1:url}')
            ->and($out)->not->toHaveKey('key');
    });

    it('warns when the key does not resolve and no id ref exists', function () {
        $context = new Context('en');

        $out = $this->link->toIds(new LinkField(['handle' => 'cta']), [
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'missing'],
        ], $context);

        expect($out)->not->toHaveKey('key')
            ->and($context->warnings())->toHaveCount(1)
            ->and($context->warnings()[0]->field)->toBe('cta');
    });

    it('keeps an existing valid ref without warning when the key does not resolve', function () {
        $context = new Context('en');

        $out = $this->link->toIds(new LinkField(['handle' => 'cta']), [
            'value' => '{entry:7@1:url}',
            'type' => 'entry',
            'key' => ['section' => 'pages', 'slug' => 'missing'],
        ], $context);

        expect($out['value'])->toBe('{entry:7@1:url}')
            ->and($context->warnings())->toBe([]);
    });
});
