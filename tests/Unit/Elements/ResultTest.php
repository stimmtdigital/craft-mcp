<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\Result;
use stimmt\craft\Mcp\elements\Warning;
use stimmt\craft\Mcp\elements\WriteMode;

describe('Result', function () {
    it('reports failure when errors exist or nothing persisted', function () {
        expect((new Result(Result::ACTION_CREATED, null))->isFailure())->toBeTrue()
            ->and((new Result(Result::ACTION_CREATED, 5, errors: ['title' => ['Required']]))->isFailure())->toBeTrue()
            ->and((new Result(Result::ACTION_UPDATED, 5, state: WriteMode::Draft))->isFailure())->toBeFalse();
    });

    it('serializes warnings and state', function () {
        $result = new Result(
            Result::ACTION_CREATED,
            7,
            draftId: 3,
            state: WriteMode::Draft,
            warnings: [new Warning('f', 'f.0', ['slug' => 'x'], 'missing')],
            cpEditUrl: 'https://cms.test/edit/7',
        );

        $array = $result->toArray();
        expect($array['action'])->toBe('created')
            ->and($array['elementId'])->toBe(7)
            ->and($array['draftId'])->toBe(3)
            ->and($array['state'])->toBe('draft')
            ->and($array['warnings'])->toHaveCount(1)
            ->and($array['warnings'][0]['message'])->toBe('missing')
            ->and($array['cpEditUrl'])->toBe('https://cms.test/edit/7')
            ->and($array['errors'])->toBe([]);
    });
});
