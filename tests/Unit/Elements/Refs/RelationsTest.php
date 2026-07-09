<?php

declare(strict_types=1);

use craft\fields\Entries;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Relations;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

describe('Relations', function () {
    beforeEach(function () {
        $this->relations = new Relations(Layouts::keysWith());
    });

    it('handles relation fields', function () {
        expect($this->relations->handles(new Entries()))->toBeTrue()
            ->and($this->relations->handles(new craft\fields\PlainText()))->toBeFalse();
    });

    it('translates ids to keys on read, keeping unresolvable ids raw', function () {
        $field = new Entries(['handle' => 'related']);

        $out = $this->relations->toKeys($field, [7, 99], new Context('en'));

        expect($out)->toBe([['section' => 'pages', 'slug' => 'about'], 99]);
    });

    it('translates keys to ids on write, warning on unresolvable keys', function () {
        $field = new Entries(['handle' => 'related']);
        $context = new Context('en');

        $out = $this->relations->toIds($field, [
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
        expect($this->relations->toKeys(new Entries(), null, new Context()))->toBeNull()
            ->and($this->relations->toIds(new Entries(), null, new Context()))->toBeNull();
    });
});
