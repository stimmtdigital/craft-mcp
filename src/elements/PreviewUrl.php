<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use Throwable;

/**
 * A link that renders an element exactly as saved, draft included, for anyone
 * holding it (#55).
 *
 * WHY: writes land as drafts, and a draft's own `url` is the canonical entry's,
 * so it renders the published version or 404s for a brand-new entry, while
 * `cpEditUrl` needs a control panel session a bearer-token client does not
 * have. Craft solves the same problem for its Share button with a preview
 * token routed through the anonymous `preview/preview` action; this mints the
 * same token. It grants no more than the caller could already read with
 * get_entry, and it expires.
 *
 * The URL comes from the first preview target when the section has one, so a
 * headless site's frontend is what renders, and from the element's own URL
 * otherwise. No URL means nothing to preview: null, and no token is minted.
 *
 * Configured from the plugin settings by support\ElementModule, since this
 * module reads nothing of the plugin's own.
 */
final readonly class PreviewUrl {
    /**
     * @param bool $enabled false returns null for every element and mints nothing
     * @param int|null $duration token lifetime in seconds; null uses Craft's own preview duration
     */
    public function __construct(
        private bool $enabled = true,
        private ?int $duration = null,
    ) {
    }

    public function for(ElementInterface $element): ?string {
        if (!$this->enabled) {
            return null;
        }

        $url = self::target($this->previewTargets($element), $element->getUrl());
        if ($url === null) {
            return null;
        }

        try {
            return $this->mint($element, $url);
        } catch (Throwable $e) {
            Craft::warning(sprintf('Could not mint a preview link for element %s: %s', $element->id, $e->getMessage()), __METHOD__);

            return null;
        }
    }

    private function mint(ElementInterface $element, string $url): ?string {
        $tokens = Craft::$app->getTokens();
        $route = self::route($element);
        $token = $this->duration === null
            ? $tokens->createPreviewToken($route)
            : $tokens->createToken(
                $route,
                null,
                DateTimeHelper::currentUTCDateTime()->add(DateTimeHelper::secondsToInterval($this->duration)),
            );

        return $token === false ? null : UrlHelper::urlWithToken($url, $token);
    }

    /**
     * The element's preview targets, or none when computing them fails.
     *
     * WHY the catch: other plugins answer Craft's preview-target event, and
     * SEOmatic's handler reads the session, which a console request (the stdio
     * transport) does not have. A preview link is a convenience, so a listener
     * that throws costs the link its frontend target, never the write.
     *
     * @return array<int, array<string, mixed>>
     */
    private function previewTargets(ElementInterface $element): array {
        try {
            return $element->getPreviewTargets();
        } catch (Throwable $e) {
            Craft::warning(sprintf('Preview targets unavailable for element %s, using its own URL: %s', $element->id, $e->getMessage()), __METHOD__);

            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $targets as Element::getPreviewTargets() returns them
     */
    public static function target(array $targets, ?string $elementUrl): ?string {
        $url = $targets[0]['url'] ?? $elementUrl;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The route Craft's own PreviewController::actionCreateToken() mints, with
     * userId null: that id only selects a user's provisional draft, and a
     * draft written through this plugin is a real draft with its own id.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function route(ElementInterface $element): array {
        return ['preview/preview', [
            'elementType' => $element::class,
            'canonicalId' => (int) $element->getCanonicalId(),
            'siteId' => (int) $element->siteId,
            'draftId' => $element->draftId ?: null,
            'revisionId' => $element->revisionId ?: null,
            'userId' => null,
        ]];
    }
}
