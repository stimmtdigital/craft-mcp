<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\Attributes;
use stimmt\craft\Mcp\elements\query\Projection;
use stimmt\craft\Mcp\elements\Reader;

describe('Attributes', function () {
    it('rejects unknown attribute names', function () {
        $source = (string) file_get_contents((new ReflectionClass(Attributes::class))->getFileName());

        expect($source)->toContain('Unknown attribute');
    });

    it('is the only place that formats payload dates', function () {
        foreach ([Reader::class, Projection::class] as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

            expect($source)->not->toContain("'Y-m-d H:i:s'");
        }
    });
});
