<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\capabilities\ReviewTools;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Gate;
use stimmt\craft\Mcp\review\DraftToReview;

/**
 * The two review tools' contract with Craft's MCP server and with the acting
 * user. Loading elements needs an install the unit suite does not have, so
 * what is pinned here is the surface and where each permission seam sits; the
 * comparisons themselves are covered on plain arrays next to this file.
 */
$reviewMethodBody = static function (string $method): string {
    $reflection = new ReflectionMethod(ReviewTools::class, $method);
    $lines = explode("\n", (string) file_get_contents((string) $reflection->getFileName()));

    return implode("\n", array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

$reviewTool = static fn (string $method): McpTool => (new ReflectionMethod(ReviewTools::class, $method))
    ->getAttributes(McpTool::class)[0]
    ->newInstance();

describe('the review tool surface', function () use ($reviewTool) {
    // Reads: Craft maps readOnlyHint to the mcp:read scope, so a read without
    // it would demand a write grant, and an editor on Lite must have them.
    it('declares both tools as Lite reads open to non-admins', function (string $method, string $name, string $title) use ($reviewTool) {
        $reflection = new ReflectionMethod(ReviewTools::class, $method);
        $tool = $reviewTool($method);

        expect($tool->name)->toBe($name)
            ->and($tool->title)->toBe($title)
            ->and($tool->annotations?->readOnlyHint)->toBeTrue()
            ->and($tool->annotations?->idempotentHint)->toBeTrue()
            ->and(Gate::requiredEdition($reflection))->toBe(Edition::Lite)
            ->and($reflection->getAttributes(RequiresAdmin::class))->toBe([]);
    })->with([
        ['diffDraft', 'stimmt_drafts_diff', 'What a draft changes'],
        ['untranslatedEntries', 'stimmt_entries_untranslated', 'Translation gaps'],
    ]);

    it('takes the parameters the brief names', function (string $method, array $parameters) {
        $names = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(ReviewTools::class, $method))->getParameters(),
        );

        expect($names)->toBe($parameters);
    })->with([
        ['diffDraft', ['id', 'site']],
        ['untranslatedEntries', ['site', 'section', 'sourceSite', 'limit', 'offset']],
    ]);

    // A caller reading "limit" as a result count would think an empty page
    // meant "nothing left to translate" while most of the install was never
    // looked at.
    it('says the limit bounds the scan, not the results', function () use ($reviewTool) {
        expect($reviewTool('untranslatedEntries')->description)
            ->toContain('limit bounds the entries SCANNED, not the entries returned');
    });
});

describe('the acting user\'s permissions', function () use ($reviewMethodBody) {
    it('routes every element read through an Authorization seam', function (string $method, string $seam) use ($reviewMethodBody) {
        expect($reviewMethodBody($method))->toContain("Authorization::{$seam}(");
    })->with([
        ['diffDraft', 'assertCanView'],
        ['liveVersion', 'assertCanView'],
        ['untranslatedEntries', 'scopeQuery'],
        ['onSite', 'scopeQuery'],
    ]);

    it('checks the draft before reading anything from it', function () use ($reviewMethodBody) {
        $body = $reviewMethodBody('diffDraft');
        $check = strpos($body, 'Authorization::assertCanView($draft)');

        // Not found is false, and false sorts below every offset, so the
        // presence has to be pinned before the order means anything.
        expect($check)->toBeInt()
            ->and($check)->toBeLessThan(strpos($body, '$this->fieldValues('));
    });

    it('bounds the scan by the window before it queries', function () use ($reviewMethodBody) {
        $body = $reviewMethodBody('untranslatedEntries');
        $guard = strpos($body, 'Window::assert(');

        expect($guard)->toBeInt()
            ->and($guard)->toBeLessThan(strpos($body, '::find()'))
            ->and($body)->toContain('->limit($limit)->offset($offset)');
    });
});

describe('the draft a diff accepts', function () {
    it('refuses a revision, naming the canonical id to call with', function () {
        $revision = new Entry(['id' => 3306, 'revisionId' => 9, 'canonicalId' => 3285, 'siteId' => 1]);

        expect(fn () => DraftToReview::assertReviewable($revision))
            ->toThrow(ToolCallException::class, 'Entry 3306 is a revision of entry 3285');
    });

    it('refuses a provisional draft, saying what it is', function () {
        $provisional = new Entry(['id' => 77, 'draftId' => 5, 'canonicalId' => 70, 'isProvisionalDraft' => true, 'siteId' => 1]);

        expect(fn () => DraftToReview::assertReviewable($provisional))
            ->toThrow(ToolCallException::class, 'Entry 77 is a provisional draft: unsaved control-panel edits of entry 70');
    });

    it('accepts a saved draft and a canonical entry', function (array $config) {
        DraftToReview::assertReviewable(new Entry(['siteId' => 1, ...$config]));

        expect(true)->toBeTrue();
    })->with([
        [['id' => 80, 'draftId' => 6, 'canonicalId' => 70]],
        [['id' => 70]],
    ]);

    // Same resolution as stimmt_drafts_publish: a canonical id stands for
    // its one pending non-provisional draft.
    it('resolves a canonical id through its non-provisional pending drafts', function () {
        $source = (string) file_get_contents((string) (new ReflectionClass(DraftToReview::class))->getFileName());

        expect($source)->toContain('->draftOf(')
            ->and($source)->toContain('->provisionalDrafts(false)')
            ->and($source)->toContain('multiple pending drafts');
    });
});
