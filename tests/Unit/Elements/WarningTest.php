<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\Warning;

describe('Warning', function () {
    it('serializes to a flat array', function () {
        $warning = new Warning('related', 'related.0', ['section' => 'pages', 'slug' => 'gone'], 'Entry not found');

        expect($warning->toArray())->toBe([
            'field' => 'related',
            'path' => 'related.0',
            'key' => ['section' => 'pages', 'slug' => 'gone'],
            'message' => 'Entry not found',
        ]);
    });
});
