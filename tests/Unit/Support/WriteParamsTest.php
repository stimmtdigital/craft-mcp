<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\support\WriteParams;

describe('WriteParams::fieldsPayload', function () {
    it('returns an empty payload for a null fields parameter', function () {
        expect(WriteParams::fieldsPayload(null))->toBe([]);
    });

    it('decodes a JSON object payload', function () {
        expect(WriteParams::fieldsPayload('{"summary": "Hello"}'))->toBe(['summary' => 'Hello']);
    });

    it('rejects malformed JSON', function () {
        WriteParams::fieldsPayload('{not json');
    })->throws(ToolCallException::class, 'Invalid JSON');

    it('rejects a JSON scalar, which is valid JSON but not a fields map', function () {
        WriteParams::fieldsPayload('"just a string"');
    })->throws(ToolCallException::class, 'Invalid JSON');
});

describe('WriteParams::mode', function () {
    it('resolves an explicit mode case-insensitively', function (string $given, WriteMode $expected) {
        expect(WriteParams::mode($given))->toBe($expected);
    })->with([
        ['draft', WriteMode::Draft],
        ['LIVE', WriteMode::Live],
        ['Draft', WriteMode::Draft],
    ]);

    it('rejects an unknown mode naming the valid ones', function () {
        WriteParams::mode('bogus');
    })->throws(ToolCallException::class, 'draft or live');

    it('falls back to the entryWriteMode setting default when no mode is given', function () {
        expect(WriteParams::mode(null))->toBe(WriteMode::Draft);
    });
});
