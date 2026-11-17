<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

use Craft;
use craft\models\Section;
use craft\models\Site;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\SiteResolver;

/**
 * What a translation scan covers: the two sites it compares and the sections
 * that exist on both, or a refusal saying why there is nothing to compare.
 */
final class TranslationScope {
    /**
     * @return array{Site, Site}
     */
    public static function sites(?string $sourceHandle, string $targetHandle): array {
        if (!Craft::$app->getIsMultiSite()) {
            throw new ToolCallException('This install has a single site, so there is nothing to translate into.');
        }

        $target = SiteResolver::resolve($targetHandle) ?? throw new ToolCallException('site is required.');
        $source = SiteResolver::resolve($sourceHandle) ?? Craft::$app->getSites()->getPrimarySite();

        if ($source->id === $target->id) {
            throw new ToolCallException(
                "site and sourceSite are both '{$target->handle}'; a site cannot be untranslated from itself."
                . ' Pass the site to check as site and the site it is translated from as sourceSite.',
            );
        }

        return [$source, $target];
    }

    /**
     * Sections whose entries exist on both sites: a section the target site
     * does not have is not untranslated there, it is simply not part of it.
     *
     * @return list<int>
     */
    public static function sectionIds(?Section $section, Site $source, Site $target): array {
        $sections = $section === null ? Craft::$app->getEntries()->getAllSections() : [$section];
        $ids = [];
        foreach ($sections as $candidate) {
            $siteIds = $candidate->getSiteIds();
            if (in_array($source->id, $siteIds, true) && in_array($target->id, $siteIds, true)) {
                $ids[] = (int) $candidate->id;
            }
        }

        if ($ids === []) {
            throw new ToolCallException(
                $section === null
                    ? "No section is enabled on both '{$source->handle}' and '{$target->handle}', so no entry has a translation to check."
                    : "Section '{$section->handle}' is not enabled on both '{$source->handle}' and '{$target->handle}'.",
            );
        }

        return $ids;
    }
}
