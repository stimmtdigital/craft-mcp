<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

describe('Writer', function () {
    beforeEach(function () {
        $this->layout = Layouts::with([new CustomField(new Entries(['handle' => 'related']))]);
    });

    it('prepares payload fields into id-resolved values', function () {
        $writer = new Writer(Translator::withDefaults(Layouts::keysWith()));

        $context = new Context('en');
        $prepared = $writer->prepare($this->layout, ['related' => [['section' => 'pages', 'slug' => 'about']]], $context);

        expect($prepared['related'])->toBe([7])
            ->and($context->warnings())->toBe([]);
    });

    it('collects warnings for unresolvable keys and keeps the rest', function () {
        $writer = new Writer(Translator::withDefaults(Layouts::keysWith(lookupId: fn (): ?int => null)));

        $context = new Context('en');
        $prepared = $writer->prepare($this->layout, ['related' => [['section' => 'x', 'slug' => 'y'], 42]], $context);

        expect($prepared['related'])->toBe([42])
            ->and($context->warnings())->toHaveCount(1);
    });

    // Shape-level: exercising Writer::result needs a saved Craft element, which
    // unit tests cannot construct (CustomFieldBehavior is runtime-generated).
    // The draft's own element id must be exposed as draftElementId, or drafts
    // become unaddressable for publish_entry (final-review C3).
    it('exposes the draft element id, not just the canonical id, on draft saves', function () {
        $source = (string) file_get_contents((new ReflectionClass(Writer::class))->getFileName());

        expect($source)->toContain('draftElementId: $element->getIsDraft() ? $element->id : null');
    });
});

// Drafts must carry the acting user as creator, or Craft's own-draft
// permission logic (publish without peer-draft rights) can never match.
it('attributes drafts to the acting user', function () {
    $source = (string) file_get_contents((new ReflectionClass(stimmt\craft\Mcp\elements\Writer::class))->getFileName());

    expect($source)->toContain('instanceof User ? $identity->id : null')
        ->and($source)->toContain('saveElementAsDraft($element, $creatorId');
});

// #61: saveElementAsDraft's markAsSaved arg must be true, or create_entry
// leaves the draft in Craft's "still being composed" state (saved = 0),
// invisible in the Entries list, search, and the Drafts status filter for
// every user, including its own creator. Craft::$app cannot be stubbed to
// intercept this call in a unit test (createElement()/getDrafts() require a
// booted application), so this pins the literal argument in source instead.
it('marks created drafts as saved so reviewers can find them (#61)', function () {
    $source = (string) file_get_contents((new ReflectionClass(stimmt\craft\Mcp\elements\Writer::class))->getFileName());

    expect($source)->toContain('saveElementAsDraft($element, $creatorId, null, null, true)');
});
