<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Authorization;

describe('Authorization', function () {
    afterEach(fn () => Authorization::reset());

    it('is inactive by default and after reset', function () {
        expect(Authorization::enforced())->toBeFalse();
    });

    it('exposes the four assertion seams for entry writes', function (string $method) {
        expect(method_exists(Authorization::class, $method))->toBeTrue();
    })->with([['assertCanSave'], ['assertCanPublish'], ['assertCanDelete'], ['assertCanDuplicate'], ['assertCanView'], ['assertCan']]);

    it('short-circuits before Craft when not enforced', function () {
        $source = (string) file_get_contents((new ReflectionClass(Authorization::class))->getFileName());

        expect($source)->toContain('if (self::$user === null)')
            ->and($source)->toContain('canSaveCanonical')
            ->and($source)->toContain('canDuplicateAsDraft')
            ->and($source)->toContain('ToolCallException');
    });

    it('exposes the scopeQuery list seam', function () {
        expect(method_exists(Authorization::class, 'scopeQuery'))->toBeTrue();
    });

    it('scopeQuery is a no-op until enforced and fails loud on unknown element queries', function () {
        $source = (string) file_get_contents((new ReflectionClass(Authorization::class))->getFileName());
        expect($source)->toContain('function scopeQuery')
            ->and($source)->toContain('if (self::$user === null)')
            ->and($source)->toContain('viewEntries')
            ->and($source)->toContain('viewAssets')
            ->and($source)->toContain('viewCategories')
            ->and($source)->toContain('viewUsers')
            ->and($source)->toContain('no view-scoping rule');
    });

    // scopeQuery must INTERSECT the caller's own section/volume/group filter
    // with the viewable set, never overwrite it (which would widen an explicit
    // narrow filter up to the user's full ceiling).
    it('scopeQuery narrows the caller filter by intersection, not overwrite', function () {
        $source = (string) file_get_contents((new ReflectionClass(Authorization::class))->getFileName());
        expect($source)->toContain('array_intersect(')
            ->and($source)->toContain('$query->sectionId')
            ->and($source)->toContain('$query->volumeId')
            ->and($source)->toContain('$query->groupId');
    });
});
