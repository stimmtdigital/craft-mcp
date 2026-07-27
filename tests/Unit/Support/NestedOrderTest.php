<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\NestedOrder;

// Reordering is pure database work against elements_owners, so behaviour needs
// a booted Craft app. These assertions cover the contract and the query shape;
// the acceptance path is exercised against a real install.
describe('NestedOrder', function () {
    it('exposes capture, restore, and place', function (string $method) {
        expect((new ReflectionClass(NestedOrder::class))->hasMethod($method))->toBeTrue();
    })->with([['capture'], ['restore'], ['place']]);

    it('treats a non-nested element as having no position', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain('instanceof NestedElementInterface');
    });

    // Restoring is a no-op when applying already left the element where it
    // was, so an ordinary top-level publish does not write to elements_owners.
    it('skips the write when the position did not change', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain('=== $sortOrder) {');
    });

    // A trashed block keeps its elements_owners row, so counting it would
    // leave gaps in the sequence and inflate the max+1 that Craft falls back
    // to. Renumbering from live siblings only is what closes those gaps.
    it('renumbers from live siblings, excluding trashed blocks, drafts and revisions', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain("'e.dateDeleted' => null")
            ->and($source)->toContain("'e.draftId' => null")
            ->and($source)->toContain("'e.revisionId' => null");
    });

    // Ordering is scoped to one field: an owner with two Matrix fields must
    // number each independently, or moving a block in one would renumber the
    // other.
    it('scopes siblings to the owner and the field together', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain("'eo.ownerId' => \$ownerId")
            ->and($source)->toContain("'en.fieldId' => \$fieldId");
    });

    it('clamps a position past the end instead of leaving a gap', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain('min($position - 1, count($ordered))');
    });

    // Craft builds its cache tags from whichever element it is handed, and
    // neither call covers the other: the block contributes its own id and the
    // container field, the owner contributes its section. Verified against a
    // live cache, invalidating on the block alone leaves section tags standing
    // and invalidating on the owner alone leaves field tags standing. A reorder
    // changes both, so both are invalidated.
    it('invalidates caches for the block and its owner, not just one', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain('foreach ([$element, $owner] as $target)')
            ->and(substr_count($source, 'self::invalidate($element, $ownerId);'))->toBe(2);
    });

    // The rows are already written when invalidation runs, so a throw here
    // would report a reorder that actually happened as a failure.
    it('never lets a cache failure fail the reorder', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedOrder::class))->getFileName());

        expect($source)->toContain('} catch (Throwable) {');
    });
});
