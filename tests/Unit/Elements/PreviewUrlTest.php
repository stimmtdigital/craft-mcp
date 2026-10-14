<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use stimmt\craft\Mcp\elements\PreviewUrl;

/**
 * The parts of a preview link that do not need a running Craft: which URL it
 * is built on and which element the token routes to. Minting the token itself
 * is Craft's, and the smoke harness covers it on a real install.
 */
describe('PreviewUrl', function () {
    it('prefers the first preview target, which is where a headless site renders', function () {
        $targets = [['label' => 'Frontend', 'url' => 'https://www.example.com/news/hello'], ['label' => 'Primary', 'url' => 'https://cms.example.com/news/hello']];

        expect(PreviewUrl::target($targets, 'https://cms.example.com/news/hello'))->toBe('https://www.example.com/news/hello');
    });

    it('falls back to the element URL when no target renders one', function () {
        expect(PreviewUrl::target([], 'https://cms.example.com/news/hello'))->toBe('https://cms.example.com/news/hello');
    });

    it('has nothing to preview when the element has no URL at all', function () {
        expect(PreviewUrl::target([], null))->toBeNull()
            ->and(PreviewUrl::target([['url' => '']], ''))->toBeNull();
    });

    // The same route the control panel's own Share button mints, except that
    // nobody's provisional draft is involved: userId stays null.
    it('routes the token to the exact draft through Craft\'s preview action', function () {
        $draft = new Entry(['id' => 42, 'siteId' => 2, 'draftId' => 9, 'canonicalId' => 7]);

        expect(PreviewUrl::route($draft))->toBe(['preview/preview', [
            'elementType' => Entry::class,
            'canonicalId' => 7,
            'siteId' => 2,
            'draftId' => 9,
            'revisionId' => null,
            'userId' => null,
        ]]);
    });

    // Turned off, nothing is minted: the element is never even asked for a URL.
    it('returns null without touching the element when turned off', function () {
        $element = new class (['id' => 7, 'siteId' => 1]) extends Entry {
            public function getPreviewTargets(): array {
                throw new LogicException('asked for preview targets');
            }

            public function getUrl(): ?string {
                throw new LogicException('asked for a URL');
            }
        };

        expect((new PreviewUrl(enabled: false))->for($element))->toBeNull();
    });

    // SEOmatic answers Craft's preview-target event by reading the session,
    // which a console request (stdio) does not have. A preview link is a
    // convenience; it must never be the reason a write fails.
    it('never lets a plugin\'s preview-target listener fail the write', function () {
        $element = new class (['id' => 7, 'siteId' => 1]) extends Entry {
            public function getPreviewTargets(): array {
                throw new RuntimeException('Session does not exist in a console request.');
            }

            public function getUrl(): ?string {
                return null;
            }
        };

        expect((new PreviewUrl())->for($element))->toBeNull();
    });

    it('routes a live element to itself, with no draft or revision', function () {
        $entry = new Entry(['id' => 7, 'siteId' => 1]);

        expect(PreviewUrl::route($entry)[1])->toMatchArray(['canonicalId' => 7, 'draftId' => null, 'revisionId' => null]);
    });
});
