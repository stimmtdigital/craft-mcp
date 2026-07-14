<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\query\Projection;
use stimmt\craft\Mcp\elements\Reader;

describe('Projection structure', function () {
    it('exposes row(entry, fields, site)', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(Projection::class, 'row'))->getParameters(),
        );

        expect($params)->toBe(['entry', 'fields', 'site']);
    });

    it('knows the projectable attributes', function () {
        $constant = (new ReflectionClass(Projection::class))->getConstant('ATTRIBUTES');

        expect($constant)->toContain('slug')->toContain('status')
            ->toContain('dateUpdated')->toContain('postDate')->toContain('url')
            ->and($constant)->not->toContain('id');
    });

    it('rejects unknown names with the valid options listed', function () {
        $source = (string) file_get_contents((new ReflectionClass(Projection::class))->getFileName());

        expect($source)->toContain('InvalidArgumentException');
    });

    it('sources attribute formulas from the shared Attributes class', function () {
        $source = (string) file_get_contents((new ReflectionClass(Projection::class))->getFileName());

        expect($source)->toContain('Attributes::value(')
            ->and($source)->not->toContain("'Y-m-d H:i:s'");
    });
});

describe('Reader::readFields', function () {
    it('exists with (element, handles, site) parameters', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(Reader::class, 'readFields'))->getParameters(),
        );

        expect($params)->toBe(['element', 'handles', 'site']);
    });
});
