<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\WriteMode;

describe('WriteMode', function () {
    it('maps setting strings to cases', function () {
        expect(WriteMode::fromSetting('draft'))->toBe(WriteMode::Draft)
            ->and(WriteMode::fromSetting('live'))->toBe(WriteMode::Live)
            ->and(WriteMode::fromSetting('LIVE'))->toBe(WriteMode::Live);
    });

    it('rejects unknown setting values', function () {
        expect(fn () => WriteMode::fromSetting('yolo'))->toThrow(ValueError::class);
    });
});
