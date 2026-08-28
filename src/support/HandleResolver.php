<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\elements\db\UserQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\models\VolumeFolder;
use Mcp\Exception\ToolCallException;

/**
 * Parameters that name something, resolved in SiteResolver's shape: null means
 * "no filter", and a value nothing answers to is a caller error that fails
 * loudly, naming what was wrong and where the right values come from.
 *
 * Craft's own query params answer an unknown handle with an empty result set,
 * which an agent reads as "nothing matches" and reports as fact, when what
 * happened is that the question never reached the content. One guard, so the
 * tools, prompts and resources that take the same parameter cannot drift apart
 * on it: an unknown section is the same sentence wherever it is asked.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class HandleResolver {
    /**
     * The entry statuses the entry tools accept, exactly as their descriptions
     * document them: Craft's own four, plus "any", this server's token for
     * every state, which the read tools turn into an unfiltered query.
     */
    private const array ENTRY_STATUSES = [
        Entry::STATUS_LIVE,
        Entry::STATUS_PENDING,
        Entry::STATUS_EXPIRED,
        Entry::STATUS_DISABLED,
        'any',
    ];

    /**
     * @phpstan-return ($handle is null ? null : Section)
     */
    public static function section(?string $handle): ?Section {
        if ($handle === null) {
            return null;
        }

        return Craft::$app->getEntries()->getSectionByHandle($handle)
            ?? throw self::unknown("Section '{$handle}'", 'Use list_sections for available handles.');
    }

    /**
     * An entry type, checked against the section when there is one and against
     * the install otherwise.
     *
     * WHY the section is optional rather than required: with one in hand the
     * useful refusal names the types THAT section allows, which is also the
     * only check a write can trust. A read filtering on type alone has no
     * section to check against, and there the only question is whether the
     * handle exists at all.
     *
     * @phpstan-return ($handle is null ? null : EntryType)
     */
    public static function entryType(?string $handle, ?Section $section = null): ?EntryType {
        if ($handle === null) {
            return null;
        }

        if ($section === null) {
            return Craft::$app->getEntries()->getEntryTypeByHandle($handle)
                ?? throw self::unknown(
                    "Entry type '{$handle}'",
                    'Use describe_entry_schema for the entry types a section allows.',
                );
        }

        foreach ($section->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        throw self::unknown(
            "Entry type '{$handle}' in section '{$section->handle}'",
            self::handles(array_map(
                static fn (EntryType $entryType): string => (string) $entryType->handle,
                $section->getEntryTypes(),
            )),
        );
    }

    /**
     * The status itself, once it is known to be one the entry tools accept.
     *
     * WHY the value comes back untouched, "any" included: null and "any" are
     * different questions to the caller as much as to the query, and the tools
     * read the difference themselves. A resolver that folded "any" into null
     * here would erase it.
     */
    public static function entryStatus(?string $status): ?string {
        if ($status === null || in_array($status, self::ENTRY_STATUSES, true)) {
            return $status;
        }

        throw self::unknown("Entry status '{$status}'", self::oneOf(self::ENTRY_STATUSES));
    }

    public static function volume(?string $handle): ?Volume {
        if ($handle === null) {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByHandle($handle)
            ?? throw self::unknown("Volume '{$handle}'", 'Use list_volumes for available handles.');
    }

    public static function categoryGroup(?string $handle): ?CategoryGroup {
        if ($handle === null) {
            return null;
        }

        return Craft::$app->getCategories()->getGroupByHandle($handle)
            ?? throw self::unknown(
                "Category group '{$handle}'",
                self::handles(array_map(
                    static fn (CategoryGroup $group): string => (string) $group->handle,
                    Craft::$app->getCategories()->getAllGroups(),
                )),
            );
    }

    public static function userGroup(?string $handle): ?UserGroup {
        if ($handle === null) {
            return null;
        }

        return Craft::$app->getUserGroups()->getGroupByHandle($handle)
            ?? throw self::unknown(
                "User group '{$handle}'",
                self::handles(array_map(
                    static fn (UserGroup $group): string => (string) $group->handle,
                    Craft::$app->getUserGroups()->getAllGroups(),
                )),
            );
    }

    /**
     * The status itself, once it is known to be one Craft has. A user status
     * is a fixed vocabulary rather than a model, so the valid set travels in
     * the message instead of a discovery tool.
     */
    public static function userStatus(?string $status): ?string {
        if ($status === null) {
            return null;
        }

        // credentialed (active or pending) is a status the query understands
        // and the element does not list, so it has to be added by hand or a
        // value that works today would start being refused.
        $statuses = [...array_map(strval(...), array_keys(User::statuses())), UserQuery::STATUS_CREDENTIALED];
        if (in_array($status, $statuses, true)) {
            return $status;
        }

        throw self::unknown("User status '{$status}'", self::oneOf($statuses));
    }

    public static function assetFolder(?int $id): ?VolumeFolder {
        if ($id === null) {
            return null;
        }

        return Craft::$app->getAssets()->getFolderById($id)
            ?? throw self::unknown("Asset folder {$id}", 'Use list_asset_folders for available folder ids.');
    }

    private static function unknown(string $subject, string $hint): ToolCallException {
        return new ToolCallException("{$subject} not found. {$hint}");
    }

    /**
     * @param string[] $handles
     */
    private static function handles(array $handles): string {
        if ($handles === []) {
            return 'This install has none.';
        }

        return 'Available handles: ' . implode(', ', $handles) . '.';
    }

    /**
     * A fixed vocabulary has no discovery tool to point at, so the values
     * travel in the message. Phrased as the instruction the next call needs,
     * which is what every other hint here does ("Use list_sections ...").
     *
     * @param string[] $values
     */
    private static function oneOf(array $values): string {
        return 'Use one of: ' . implode(', ', $values) . '.';
    }
}
