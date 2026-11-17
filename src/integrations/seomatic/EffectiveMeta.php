<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\seomatic;

use Craft;
use craft\elements\Entry;
use nystudio107\seomatic\Seomatic;

/**
 * An entry's SEO metadata as SEOmatic resolves it: global bundle, then the
 * section's content bundle, then the entry's SEO Settings field, with every
 * template parsed against the entry.
 *
 * WHY SEOmatic's preview path rather than reading the field and bundle: the
 * merge is SEOmatic's own (it parses each layer to decide which blank values
 * fall through, services/MetaContainers.php:537-557), and the bundle values
 * are Twig ("{{ seomatic.meta.seoImage }}", "{{ entry.title }}") that only
 * SEOmatic's sandboxed renderer evaluates. Recomputing that here would be a
 * second SEOmatic that drifts from the first.
 *
 * The call sequence is the one SEOmatic uses to preview many elements in one
 * request, which is what a scan is: reset the preview guard per element
 * (fields/SeoSettings.php:392), set the current site and mark the request
 * headless (helpers/Container.php:69,114), then previewMetaContainers() with
 * the element (fields/SeoSettings.php:372). It needs no web request: the
 * request-only parts of loadMetaContainers() are behind isConsoleRequest
 * checks (services/MetaContainers.php:385-410), so it runs over stdio too.
 *
 * Values are read from metaGlobalVars after parsing, not from the rendered
 * tags, because the tags apply render-time rewrites this report must not
 * see: titles and descriptions truncated to the max length, and robots forced
 * to "none" on previews and non-200 responses (models/metatag/RobotsTag.php:83-104).
 */
final class EffectiveMeta {
    public const string BASIS = 'seomatic';

    public function rules(): SeoRules {
        return new SeoRules(Seomatic::$settings->maxTitleLength, Seomatic::$settings->maxDescriptionLength);
    }

    public function of(Entry $entry): SeoSnapshot {
        $sites = Craft::$app->getSites();
        $previousSite = $sites->getCurrentSite();
        $previousHeadless = Seomatic::$headlessRequest;
        $previousElement = Seomatic::$matchedElement;

        try {
            $sites->setCurrentSite($entry->siteId);
            Seomatic::$previewingMetaContainers = false;
            Seomatic::$headlessRequest = true;

            $containers = Seomatic::$plugin->getMetaContainers();
            $containers->previewMetaContainers((string) $entry->uri, (int) $entry->siteId, true, true, $entry);
            $vars = $containers->metaGlobalVars;

            return new SeoSnapshot(
                (string) $vars->seoTitle,
                (string) $vars->seoDescription,
                (string) $vars->ogImage,
                (string) $vars->robots,
            );
        } finally {
            // Left as SEOmatic found them, so nothing later in this request
            // renders meta for the last entry scanned.
            Seomatic::$previewingMetaContainers = false;
            Seomatic::$headlessRequest = $previousHeadless;
            Seomatic::setMatchedElement($previousElement);
            $sites->setCurrentSite($previousSite);
        }
    }
}
