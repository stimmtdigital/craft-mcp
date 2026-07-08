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
