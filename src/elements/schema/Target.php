<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use Craft;
use craft\elements\User;
use craft\fields\BaseRelationField;

/**
 * What a relation field accepts: the element type it relates to, and the
 * containers it is restricted to, spelled in the same natural keys the write
 * payload is made of.
 *
 * WHY not the raw setting: Craft stores a field's sources as element source
 * keys, which carry uids ("section:70b2614b-639a-4de2-8c11-3851d36d6f8c").
 * This is the one place in a schema description that tells a caller WHICH
 * section a relation takes, and the caller then writes
 * {"section": "blog", "slug": "..."}, so a uid answers the question in a
 * vocabulary the write side does not speak. Each source is reported as the
 * natural-key parts it pins down instead: {"section": "blog"}.
 *
 * WHY a dead source is named rather than passed through: a field configured
 * against a section that has since been deleted stores a uid nothing on the
 * install answers to. The stale setting is the install's, but handing it back
 * under the same key as a working source is the server's own error, because it
 * reads as a section the caller could go and use.
 *
 * WHY the lookups sit here rather than behind the tools' shared resolver: the
 * elements module imports nothing outside Craft and itself, and it asks Craft
 * directly wherever it needs an install fact, exactly as the natural-key
 * resolution in refs does.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Target {
    /**
     * Source keys with no uid in them: Craft's own named sets ("singles",
     * "admins", "credentialed", "inactive"), which state a rule rather than
     * name a container, and so have no handle to resolve to.
     */
    private const string SET = 'set';

    /**
     * A source key that could not be turned into a handle: the uid names
     * nothing on the install, or the prefix belongs to an element type this
     * server has no lookup for. Reported verbatim, since the raw key is what a
     * human needs to find the field setting that produced it.
     */
    private const string UNRESOLVED = 'unresolved';

    /**
     * @return array{elementType: class-string, sources: list<array<string, string>>|string}
     */
    public function of(BaseRelationField $field): array {
        return [
            'elementType' => $field::elementType(),
            'sources' => $this->sources($field),
        ];
    }

    /**
     * @return list<array<string, string>>|string
     */
    private function sources(BaseRelationField $field): array|string {
        $configured = $this->configured($field);

        // Craft stores "every source" as the bare string rather than as a
        // list, and it stays one here: there is nothing to resolve, and a
        // one-element list would read as one specific allowed container.
        if (!is_array($configured)) {
            return $configured;
        }

        return array_map(
            fn (mixed $source): array => $this->source((string) $source, $field::elementType()),
            array_values($configured),
        );
    }

    /**
     * The sources setting as configured, reading the raw settings rather than
     * getInputSources(): the entries field overrides that one to filter by the
     * acting user's permissions, and the console MCP process has no user
     * session to filter against. Schema description wants the configured
     * sources anyway.
     *
     * Craft keeps two settings and picks between them on the same flag
     * getInputSources() reads. `sources` holds the list for fields that allow
     * several (entries, users), while the singular `source` holds the one key
     * for the fields that allow exactly one (categories, tags), where
     * `sources` keeps its untouched '*' default and would otherwise report a
     * field pinned to one group as accepting every group.
     *
     * @return string[]|string
     */
    private function configured(BaseRelationField $field): array|string {
        if (!$field->allowMultipleSources) {
            return $field->source === null ? '*' : [$field->source];
        }

        return is_array($field->sources) ? $field->sources : '*';
    }

    /**
     * @return array<string, string>
     */
    private function source(string $source, string $elementType): array {
        if (!str_contains($source, ':')) {
            return [self::SET => $source];
        }

        [$prefix, $uid] = explode(':', $source, 2);

        return $this->parts($prefix, $uid, $elementType) ?? [self::UNRESOLVED => $source];
    }

    /**
     * The natural-key parts one stored source key pins down, or null when
     * nothing on this install answers to it.
     *
     * Null rather than an exception: this is the install's own configuration
     * read back, not a caller naming something, and one dead field setting
     * must not take a whole schema description down.
     *
     * @param string $elementType the field's target class, which is what tells
     *                            a category group from a user group: both are
     *                            stored as "group:<uid>"
     *
     * @return array<string, string>|null
     */
    private function parts(string $prefix, string $uid, string $elementType): ?array {
        return match ($prefix) {
            'section' => $this->part('section', Craft::$app->getEntries()->getSectionByUid($uid)?->handle),
            'volume' => $this->part('volume', Craft::$app->getVolumes()->getVolumeByUid($uid)?->handle),
            'taggroup' => $this->part('group', Craft::$app->getTags()->getTagGroupByUid($uid)?->handle),
            'group' => $this->part('group', $this->group($uid, $elementType)),
            'folder' => $this->folder($uid),
            default => null,
        };
    }

    /**
     * @return array<string, string>|null
     */
    private function part(string $name, ?string $handle): ?array {
        return $handle === null ? null : [$name => $handle];
    }

    private function group(string $uid, string $elementType): ?string {
        return $elementType === User::class
            ? Craft::$app->getUserGroups()->getGroupByUid($uid)?->handle
            : Craft::$app->getCategories()->getGroupByUid($uid)?->handle;
    }

    /**
     * A folder source pins down more of an asset's key than a volume does: the
     * volume plus the path inside it, which is the {volume, path, filename}
     * shape the write side takes. The path is left out at the volume root,
     * where the asset key leaves it out too.
     *
     * @return array<string, string>|null
     */
    private function folder(string $uid): ?array {
        $folder = Craft::$app->getAssets()->getFolderByUid($uid);

        // No volume id is Craft's temporary upload folder, which is not
        // somewhere a relation can be pointed at.
        if ($folder === null || $folder->volumeId === null) {
            return null;
        }

        $volume = (string) $folder->getVolume()->handle;
        $path = (string) ($folder->path ?? '');

        return $path === '' ? ['volume' => $volume] : ['volume' => $volume, 'path' => $path];
    }
}
