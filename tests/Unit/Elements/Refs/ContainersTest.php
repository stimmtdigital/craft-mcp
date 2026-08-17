<?php

declare(strict_types=1);

use craft\base\FieldInterface;
use craft\fields\Addresses;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Containers;

function upcaseRecurse(): Closure {
    return fn (FieldInterface $field, string $type, array $fields, Context $context, bool $toKeys): array => array_map(
        fn (mixed $v): mixed => is_string($v) ? strtoupper($v) . ':' . $field->handle . ':' . $type . ':' . ($toKeys ? 'K' : 'I') : $v,
        $fields,
    );
}

describe('Containers', function () {
    // Block order cannot survive the wire: blocks are keyed by their numeric
    // entry id, and JSON objects with integer-like keys are re-ordered
    // ascending by most clients, so an agent would read a page's blocks in id
    // order and, writing that payload back, silently reshuffle the field
    // (Craft renumbers sortOrder from value order).
    it('numbers blocks in field order on the way out', function () {
        $blocks = [
            480 => ['type' => 'text', 'fields' => ['body' => 'second in id, first on the page']],
            475 => ['type' => 'text', 'fields' => ['body' => 'first in id, second on the page']],
        ];

        $out = (new Containers(upcaseRecurse()))->toKeys(new Matrix(), $blocks, new Context());

        expect($out[480]['position'])->toBe(1)
            ->and($out[475]['position'])->toBe(2);
    });

    it('restores the intended order on the way in, whatever the key order', function () {
        // Exactly what a JavaScript client hands back: keys ascending by id,
        // positions still saying which one comes first.
        $blocks = [
            475 => ['type' => 'text', 'position' => 2, 'fields' => ['body' => 'b']],
            480 => ['type' => 'text', 'position' => 1, 'fields' => ['body' => 'a']],
        ];

        $out = (new Containers(upcaseRecurse()))->toIds(new Matrix(), $blocks, new Context());

        expect(array_keys($out))->toBe([480, 475]);
    });

    it('never passes position through to Craft', function () {
        $blocks = [
            475 => ['type' => 'text', 'position' => 2, 'fields' => ['body' => 'b']],
            480 => ['type' => 'text', 'position' => 1, 'fields' => ['body' => 'a']],
        ];

        $out = (new Containers(upcaseRecurse()))->toIds(new Matrix(), $blocks, new Context());

        expect($out[480])->not->toHaveKey('position')
            ->and($out[475])->not->toHaveKey('position');
    });

    // Positions are advisory: a hand-written payload without them is saved in
    // the order it was written, which is the documented behavior.
    it('keeps the given order when no block carries a position', function () {
        $blocks = [
            'new2' => ['type' => 'text', 'fields' => ['body' => 'b']],
            'new1' => ['type' => 'text', 'fields' => ['body' => 'a']],
        ];

        $out = (new Containers(upcaseRecurse()))->toIds(new Matrix(), $blocks, new Context());

        expect(array_keys($out))->toBe(['new2', 'new1']);
    });

    it('orders the positioned blocks and appends the rest', function () {
        // The realistic payload: an agent reads a field, keeps the positions it
        // was handed, and inserts one new block without inventing a position
        // for it. Skipping the sort here (the old all-or-nothing rule) left the
        // whole field in transport order, which for id-keyed blocks means
        // ascending by id rather than page order.
        $blocks = [
            'existing2' => ['type' => 'text', 'position' => 2, 'fields' => ['body' => 'second']],
            'new1' => ['type' => 'text', 'fields' => ['body' => 'added']],
            'existing1' => ['type' => 'text', 'position' => 1, 'fields' => ['body' => 'first']],
        ];

        $out = (new Containers(upcaseRecurse()))->toIds(new Matrix(), $blocks, new Context());

        expect(array_keys($out))->toBe(['existing1', 'existing2', 'new1'])
            ->and($out['existing1'])->not->toHaveKey('position');
    });

    it('leaves a single container value untouched', function () {
        $value = ['fields' => ['body' => 'x']];

        $out = (new Containers(upcaseRecurse()))->toKeys(new ContentBlock(), $value, new Context());

        expect($out)->not->toHaveKey('position');
    });

    it('handles the three container field types', function () {
        $containers = new Containers(upcaseRecurse());

        expect($containers->handles(new Matrix()))->toBeTrue()
            ->and($containers->handles(new ContentBlock()))->toBeTrue()
            ->and($containers->handles(new Addresses()))->toBeTrue()
            ->and($containers->handles(new craft\fields\PlainText()))->toBeFalse();
    });

    it('recurses into matrix blocks both directions, passing the container field and type handle', function () {
        $containers = new Containers(upcaseRecurse());
        $value = [
            '12' => ['type' => 'text', 'enabled' => false, 'title' => 'Kept', 'fields' => ['body' => 'hello']],
            'new1' => ['type' => 'quote', 'enabled' => true, 'fields' => ['body' => 'world']],
        ];

        $keys = $containers->toKeys(new Matrix(['handle' => 'content']), $value, new Context());
        $ids = $containers->toIds(new Matrix(['handle' => 'content']), $value, new Context());

        expect($keys['12']['fields']['body'])->toBe('HELLO:content:text:K')
            ->and($keys['12']['enabled'])->toBeFalse()
            ->and($keys['12']['title'])->toBe('Kept')
            ->and($keys['new1']['fields']['body'])->toBe('WORLD:content:quote:K')
            ->and($ids['new1']['fields']['body'])->toBe('WORLD:content:quote:I')
            ->and(array_keys($keys))->toBe([12, 'new1']);
    });

    it('recurses into a single content block with the field and an empty type handle', function () {
        $containers = new Containers(upcaseRecurse());

        $out = $containers->toKeys(new ContentBlock(['handle' => 'seo']), ['fields' => ['pitch' => 'buy']], new Context());

        expect($out['fields']['pitch'])->toBe('BUY:seo::K');
    });

    it('passes non-array values through', function () {
        $containers = new Containers(upcaseRecurse());

        expect($containers->toKeys(new Matrix(), null, new Context()))->toBeNull();
    });
});
