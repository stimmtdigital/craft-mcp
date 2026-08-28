<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use stimmt\craft\Mcp\elements\NestedSlugs;
use stimmt\craft\Mcp\elements\Writer;

describe('NestedSlugs', function () {
    beforeEach(function () {
        $this->source = (string) file_get_contents((new ReflectionClass(NestedSlugs::class))->getFileName());
    });

    // An Entry with no typeId answers a null field layout (Entry::getFieldLayout
    // swallows the InvalidConfigException), so the whole walk runs here with no
    // Craft application behind it. That it completes rather than fatally
    // reaching for a service is the point: the clear-up has to stay out of the
    // way of every write with nothing nested to clean.
    it('is a no-op for an owner with no container fields', function () {
        NestedSlugs::clearPlaceholders(new Entry(['id' => 1, 'siteId' => 1]));
    })->throwsNoExceptions();

    // Craft's own helper decides what a placeholder is. A hand-rolled
    // '__temp_' prefix check would drift from ElementHelper the day Craft
    // changes the marker, and would then either miss real ones or eat a slug
    // that only looks like one.
    it('recognises placeholders through Craft\'s helper', function () {
        expect($this->source)->toContain('ElementHelper::isTempSlug($element->slug)');
    });

    // null, not the canonical block's slug: SlugValidator only ever stamps the
    // marker over an EMPTY slug, so the marker's presence is itself the record
    // that there was none. Copying a canonical value in would be an invention.
    it('clears a placeholder to null', function () {
        expect($this->source)->toContain('$element->slug = null;');
    });

    // Load-bearing argument. The marker lands on ONE elements_sites row, the
    // site the draft was written on. Letting Craft push the null out to the
    // other sites would wipe whatever real slug they hold, turning a repair
    // into the wider version of the bug it repairs.
    it('writes the cleared slug to the element\'s own site only', function () {
        expect($this->source)->toContain('updateOtherSites: false, updateDescendants: false');
    });

    // A container field is walked, a relation field is not, and even inside a
    // container the ownership test is what stands between this and a related
    // element whose slug is real and is nobody else's to rewrite.
    it('touches only elements the owner actually owns', function () {
        expect($this->source)->toContain('$field instanceof ElementContainerFieldInterface')
            ->and($this->source)->toContain('$child instanceof NestedElementInterface')
            ->and($this->source)->toContain('$child->getOwnerId() === $owner->id');
    });
});

// The clear-up cannot happen before the save: the owner's own validation walks
// its blocks (Matrix::validateEntries) and re-stamps every one it still finds
// with an empty slug, so a pre-save clear is undone by the very save it was
// guarding. It also must not run on a failed save, which rolled back. Both
// write paths need it, because both can put an existing block onto a draft.
// Behavior needs a real install; this pins the placement.
describe('Writer clears placeholders after the save', function () {
    it('calls the clear-up after a successful save in both write paths', function (string $method) {
        $reflection = new ReflectionMethod(Writer::class, $method);
        $lines = explode("\n", (string) file_get_contents($reflection->getFileName()));
        $body = implode("\n", array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $save = strrpos($body, '$saved =');
        $guard = strpos($body, 'if ($saved) {');
        $clear = strpos($body, 'NestedSlugs::clearPlaceholders($element);');

        expect($save)->toBeInt()
            ->and($guard)->toBeInt()
            ->and($clear)->toBeInt()
            ->and($guard)->toBeGreaterThan($save)
            ->and($clear)->toBeGreaterThan($guard);
    })->with(['create', 'update']);
});
