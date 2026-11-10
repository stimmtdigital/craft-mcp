<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\capabilities\EntryWorkflowTools;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Gate;

describe('EntryWorkflowTools structure', function () {
    // Publishing and copying across sites are the Pro edition's validated
    // writes. They are not #[Dangerous]: that gate is for code, SQL and files,
    // and production forces it off, which would take publishing with it.
    it('exposes the two workflow tools as Pro writes, not dangerous ones', function (string $method, string $name) {
        $reflection = new ReflectionMethod(EntryWorkflowTools::class, $method);

        expect($reflection->getAttributes(McpTool::class)[0]->newInstance()->name)->toBe($name)
            ->and(Gate::requiredEdition($reflection))->toBe(Edition::Pro)
            ->and($reflection->getAttributes(Dangerous::class))->toBe([]);
    })->with([
        ['publishEntry', 'stimmt_drafts_publish'],
        ['copyEntryToSite', 'stimmt_entries_copy_to_site'],
    ]);

    // Shape-level (behavior needs a Craft app; dev/integration-elements.py is
    // the acceptance test): a canonical id must resolve to its single pending
    // non-provisional draft, fail loudly on several, and refuse a no-op
    // publish (final-review C3).
    it('publishes a canonical id through its pending draft lookup', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryWorkflowTools::class))->getFileName());

        expect($source)->toContain('->draftOf(')
            ->and($source)->toContain('->provisionalDrafts(false)')
            ->and($source)->toContain('multiple pending drafts')
            ->and($source)->toContain('nothing to publish');
    });
});

describe('stimmt_drafts_publish block ordering', function () {
    // Applying a draft of a single nested block silently moved it to the end
    // of its Matrix field: applyDraft clones the draft with draftId nulled,
    // so NestedElementTrait::saveOwnership() skips its sortOrder recovery
    // lookup and falls through to max+1. The fix presets the canonical's
    // current position on the draft BEFORE the apply, because saveOwnership
    // checks isset(sortOrder) before either fallback and the preset value is
    // then written inside the apply transaction. NestedPosition::capture's
    // guard behavior is covered in NestedPositionTest; this pins the preset
    // and its position relative to the apply call, which needs a real
    // install to run.
    it('presets the captured sort order on the draft before applying it', function () {
        $reflection = new ReflectionMethod(EntryWorkflowTools::class, 'applyDraft');
        $file = (string) file_get_contents($reflection->getFileName());
        $body = implode("\n", array_slice(
            explode("\n", $file),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $capture = strpos($body, 'NestedPosition::capture($draft)');
        $preset = strpos($body, '$draft->setSortOrder(');
        $apply = strpos($body, '->applyDraft($draft)');

        expect($capture)->toBeInt()
            ->and($preset)->toBeInt()
            ->and($apply)->toBeInt()
            ->and($capture)->toBeLessThan($preset)
            ->and($preset)->toBeLessThan($apply);
    });
});

// #76: Drafts::applyDraft() does not validate, so a draft missing a required
// field went live with success: true, where the control panel refuses with
// "Couldn't apply draft". Both publish paths now validate under Craft's live
// scenario first, as ElementsController::actionApplyDraft() does, and answer
// a failure with the field errors instead of publishing.
describe('stimmt_drafts_publish validates before it publishes (#76)', function () {
    $body = static function (string $method): string {
        $reflection = new ReflectionMethod(EntryWorkflowTools::class, $method);
        $lines = explode("\n", (string) file_get_contents($reflection->getFileName()));

        return implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    };

    it('validates a draft before applying it', function () use ($body) {
        $source = $body('applyDraft');
        $validate = strpos($source, '$this->liveErrors($draft)');
        $apply = strpos($source, '->applyDraft($draft)');

        expect($validate)->toBeInt()->and($apply)->toBeInt()->and($validate)->toBeLessThan($apply);
    });

    it('validates under the live scenario, as the control panel does', function () use ($body) {
        $source = $body('liveErrors');
        $live = strpos($source, 'Element::SCENARIO_LIVE');
        $save = strpos($source, '->saveElement(');

        expect($live)->toBeInt()->and($save)->toBeInt()->and($live)->toBeLessThan($save)
            ->and($source)->toContain('propagateRequired = true')
            ->and($source)->toContain('crossSiteValidate:');
    });

    it('answers an invalid draft with its field errors instead of applying it', function () use ($body) {
        expect($body('applyDraft'))->toContain('Response::failure(')
            ->and($body('applyDraft'))->toContain("'errors' =>");
    });

    it('validates a disabled entry under the live scenario before enabling it', function () use ($body) {
        $source = $body('enable');
        $live = strpos($source, 'Element::SCENARIO_LIVE');
        $save = strpos($source, '->saveElement(');

        expect($live)->toBeInt()->and($save)->toBeInt()->and($live)->toBeLessThan($save)
            ->and($body('publishCanonical'))->toContain('Response::failure(');
    });
});
