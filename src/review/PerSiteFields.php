<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

use craft\elements\Entry;
use stimmt\craft\Mcp\elements\LayoutFields;

/**
 * Which of an entry's values the source and target sites keep apart, which
 * are the only values that CAN be untranslated between them.
 *
 * WHY the translation key and not only getIsTranslatable(): a field translated
 * per language, or per site group, is translatable and still holds ONE value
 * for two sites that share the language or the group. Those two sites are
 * meant to agree, and reporting the agreement as "same-as-source" would list
 * every such field on every entry. Craft decides whether two sites share a
 * value by comparing translation keys when it propagates
 * (FieldInterface::getTranslationKey()), so the same comparison decides here.
 * A Matrix field supports only per-site translation, so its keys always differ
 * when it is translatable at all.
 */
final class PerSiteFields {
    /**
     * Custom field handles holding a separate value on each of the two sites.
     *
     * @return list<string>
     */
    public static function fieldHandles(Entry $source, Entry $target): array {
        $handles = [];
        foreach (LayoutFields::of($source->getFieldLayout()) as $handle => $field) {
            if ($field->getIsTranslatable($target) && $field->getTranslationKey($source) !== $field->getTranslationKey($target)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Title and slug, when the entry type keeps them apart for these two
     * sites. A title the entry type generates from a format is left out: it
     * follows the fields it is made from, which are checked on their own.
     *
     * @return list<string>
     */
    public static function attributeHandles(Entry $source, Entry $target): array {
        $handles = [];

        if ($source->getType()->hasTitleField && self::titleApart($source, $target)) {
            $handles[] = 'title';
        }

        if ($source->getIsSlugTranslatable() && $source->getSlugTranslationKey() !== $target->getSlugTranslationKey()) {
            $handles[] = 'slug';
        }

        return $handles;
    }

    private static function titleApart(Entry $source, Entry $target): bool {
        return $source->getIsTitleTranslatable() && $source->getTitleTranslationKey() !== $target->getTitleTranslationKey();
    }
}
