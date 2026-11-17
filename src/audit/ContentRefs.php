<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * The reference tags inside one `elements_sites.content` value.
 *
 * Craft 5 stores every custom field of an element-site as one JSON object
 * keyed by field layout element UID. Rich text (CKEditor, Redactor) keeps its
 * HTML as a string holding `{entry:12@1:url||...}` tags, and a Link field
 * keeps `{"value": "{entry:12@1:url}", "type": "entry"}`, so walking every
 * string under each key finds both without knowing the field types.
 *
 * WHY decoded rather than matched against the raw JSON text: each tag has to
 * be attributed to the field it sits in, and only the decoded object says
 * which top-level key a string belongs to.
 */
final class ContentRefs {
    /**
     * @return list<FieldRef>
     */
    public static function inJson(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }

        $content = json_decode($json, true);

        // A column Craft wrote is valid JSON; anything else holds no fields
        // to read links from, which is the same answer as an empty object.
        return is_array($content) ? self::inContent($content) : [];
    }

    /**
     * @param array<array-key, mixed> $content
     * @return list<FieldRef>
     */
    public static function inContent(array $content): array {
        $refs = [];
        foreach ($content as $uid => $value) {
            $refs = [...$refs, ...self::inValue((string) $uid, $value)];
        }

        return $refs;
    }

    /**
     * @return list<FieldRef>
     */
    private static function inValue(string $uid, mixed $value): array {
        $tags = array_merge(...array_map(RefTagParser::parse(...), self::strings($value)));

        return array_map(static fn (RefTag $tag): FieldRef => new FieldRef($uid, $tag), $tags);
    }

    /**
     * Every string anywhere inside a field value, however deeply nested.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        array_walk_recursive($value, static function (mixed $leaf) use (&$strings): void {
            if (is_string($leaf)) {
                $strings[] = $leaf;
            }
        });

        return $strings;
    }
}
