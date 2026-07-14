<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use craft\base\ElementInterface;
use craft\elements\Entry;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Single source for the payload attribute formulas shared by the full Reader
 * payload and the slim Projection rows, so formats never drift between them.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Attributes {
    public static function value(ElementInterface $element, string $attribute): mixed {
        return match ($attribute) {
            'id' => $element->id,
            'canonicalId' => $element->getCanonicalId(),
            'title' => $element->title,
            'slug' => $element->slug,
            'status' => $element->getStatus(),
            'state' => $element->getIsDraft() ? WriteMode::Draft->value : WriteMode::Live->value,
            'draftId' => $element->draftId ?? null,
            'siteId' => $element->siteId,
            'siteHandle' => $element->getSite()->handle,
            'dateCreated' => self::date($element->dateCreated),
            'dateUpdated' => self::date($element->dateUpdated),
            'url' => $element->getUrl(),
            'cpEditUrl' => $element->getCpEditUrl(),
            'sectionHandle' => $element instanceof Entry ? $element->getSection()?->handle : null,
            'typeHandle' => $element instanceof Entry ? $element->getType()->handle : null,
            'authorId' => $element instanceof Entry ? $element->getAuthorId() : null,
            'postDate' => $element instanceof Entry ? self::date($element->postDate) : null,
            'expiryDate' => $element instanceof Entry ? self::date($element->expiryDate) : null,
            default => throw new InvalidArgumentException("Unknown attribute '{$attribute}'"),
        };
    }

    private static function date(?DateTimeInterface $date): ?string {
        return $date?->format('Y-m-d H:i:s');
    }
}
