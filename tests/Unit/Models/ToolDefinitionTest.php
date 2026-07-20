<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\models\ToolDefinition;

describe('ToolDefinition edition', function () {
    it('defaults requiredEdition to Standard', function () {
        $def = ToolDefinition::fromArray(['name' => 'get_entry']);
        expect($def->requiredEdition)->toBe(Edition::Standard);
    });

    it('accepts a requiredEdition and serializes it', function () {
        $def = ToolDefinition::fromArray([
            'name' => 'create_entry',
            'requiredEdition' => Edition::Pro,
        ]);
        expect($def->requiredEdition)->toBe(Edition::Pro)
            ->and($def->toArray()['requiredEdition'])->toBe('pro');
    });
});
