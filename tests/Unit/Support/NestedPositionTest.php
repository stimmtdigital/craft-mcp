<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use craft\elements\GlobalSet;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\NestedPosition;

describe('NestedPosition::reorder', function () {
    it('moves a block to an earlier position and reports it', function () {
        [$order, $taken] = NestedPosition::reorder([10, 20, 30, 40], 30, 2);

        expect($order)->toBe([10, 30, 20, 40])
            ->and($taken)->toBe(2);
    });

    it('moves a block to a later position', function () {
        [$order, $taken] = NestedPosition::reorder([10, 20, 30, 40], 10, 3);

        expect($order)->toBe([20, 30, 10, 40])
            ->and($taken)->toBe(3);
    });

    it('moves a block to the front', function () {
        [$order, $taken] = NestedPosition::reorder([10, 20, 30], 30, 1);

        expect($order)->toBe([30, 10, 20])
            ->and($taken)->toBe(1);
    });

    it('clamps a position past the end and reports the position actually taken', function () {
        [$order, $taken] = NestedPosition::reorder([10, 20, 30], 10, 99);

        expect($order)->toBe([20, 30, 10])
            ->and($taken)->toBe(3);
    });

    it('keeps the order intact when the block already holds the position', function () {
        [$order, $taken] = NestedPosition::reorder([10, 20, 30], 20, 2);

        expect($order)->toBe([10, 20, 30])
            ->and($taken)->toBe(2);
    });

    it('handles a single-block field', function () {
        [$order, $taken] = NestedPosition::reorder([10], 10, 5);

        expect($order)->toBe([10])
            ->and($taken)->toBe(1);
    });

    it('rejects a position below one', function () {
        NestedPosition::reorder([10, 20], 10, 0);
    })->throws(ToolCallException::class, 'position');

    it('rejects a block id that is not in the list', function () {
        NestedPosition::reorder([10, 20], 99, 1);
    })->throws(ToolCallException::class, '99');
});

describe('NestedPosition::capture', function () {
    // Both null guards run behaviorally on real element instances; only the
    // elements_owners row read itself needs a database and is pinned
    // structurally below.
    it('returns null for an element type that is never nested', function () {
        expect(NestedPosition::capture(new GlobalSet(['siteId' => 1, 'id' => 3])))->toBeNull();
    });

    it('returns null for an entry that has no owner', function () {
        expect(NestedPosition::capture(new Entry(['siteId' => 1, 'id' => 5])))->toBeNull();
    });

    // The row read must target the CANONICAL block's CURRENT row: a draft's
    // own ownership row still carries the position from draft-creation time,
    // so a block reordered after being drafted would publish back to its old
    // spot if the draft's row were read instead. Reading the row needs a
    // database, so the query's shape is pinned here; the round trip runs on a
    // real install.
    it('reads the canonical row from elements_owners, not the draft copy', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedPosition::class))->getFileName());

        expect($source)->toContain('Table::ELEMENTS_OWNERS')
            ->and($source)->toContain('getCanonicalId()')
            ->and($source)->toContain('getOwnerId()');
    });
});

describe('NestedPosition::move', function () {
    // move() saves the owner through Craft's supported value path, which
    // needs a booted application; the reorder arithmetic is covered
    // behaviorally above, and the save contract is pinned here. The contract:
    // the field value must be fetched with status(null) (core deletes any
    // block missing from the value, so disabled siblings must ride along),
    // reordered via setCachedResult + setFieldValue (which marks the field
    // dirty so NestedElementManager::saveNestedElements() renumbers by value
    // order inside its own transaction), and the owner saved through
    // saveElement. Never write elements_owners directly.
    it('reorders through the supported owner-save path, disabled blocks included', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedPosition::class))->getFileName());

        $statusNull = strpos($source, '->status(null)->all()');
        $cached = strpos($source, '->setCachedResult(');
        $setValue = strpos($source, '->setFieldValue(');
        $save = strpos($source, '->saveElement(');

        expect($statusNull)->toBeInt()
            ->and($cached)->toBeInt()
            ->and($setValue)->toBeInt()
            ->and($save)->toBeInt()
            ->and($statusNull)->toBeLessThan($cached)
            ->and($cached)->toBeLessThan($setValue)
            ->and($setValue)->toBeLessThan($save)
            ->and($source)->not->toContain('Db::update');
    });

    // Blocks are matched by canonical id, not element id: under a saved owner
    // draft, an edited block is represented by a shed copy whose element id
    // differs from the canonical block the caller named, and the copy's
    // canonicalId is the only stable link between them.
    it('matches blocks by canonical id', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedPosition::class))->getFileName());

        expect($source)->toContain('$block->getCanonicalId()');
    });

    it('names the owner and field when the block is not among the field blocks', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedPosition::class))->getFileName());

        expect($source)->toContain('is not among the blocks of field');
    });
});
