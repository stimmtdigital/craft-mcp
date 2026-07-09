<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\http\Scope;

describe('Scope', function () {
    it('readonly allows only non-dangerous tools', function () {
        expect(Scope::ReadOnly->allows(ToolCategory::CONTENT->value, false))->toBeTrue()
            ->and(Scope::ReadOnly->allows(ToolCategory::CONTENT->value, true))->toBeFalse()
            ->and(Scope::ReadOnly->allows(ToolCategory::DEBUGGING->value, true))->toBeFalse();
    });

    it('content adds dangerous content tools and nothing else dangerous', function () {
        expect(Scope::Content->allows(ToolCategory::CONTENT->value, true))->toBeTrue()
            ->and(Scope::Content->allows(ToolCategory::SCHEMA->value, false))->toBeTrue()
            ->and(Scope::Content->allows(ToolCategory::DEBUGGING->value, true))->toBeFalse()
            ->and(Scope::Content->allows(ToolCategory::DATABASE->value, true))->toBeFalse()
            ->and(Scope::Content->allows(ToolCategory::GRAPHQL->value, true))->toBeFalse()
            ->and(Scope::Content->allows(ToolCategory::SYSTEM->value, true))->toBeFalse()
            ->and(Scope::Content->allows(ToolCategory::BACKUP->value, true))->toBeFalse();
    });

    it('full allows everything', function () {
        expect(Scope::Full->allows(ToolCategory::DEBUGGING->value, true))->toBeTrue();
    });

    it('parses lenient input and rejects unknown values', function () {
        expect(Scope::fromInput(' Content '))->toBe(Scope::Content);
        expect(fn () => Scope::fromInput('admin'))->toThrow(InvalidArgumentException::class, 'readonly');
    });
});
