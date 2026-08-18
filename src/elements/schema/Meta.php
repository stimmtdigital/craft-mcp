<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\schema;

use craft\base\Element;
use craft\models\EntryType;
use yii\base\Model;

/**
 * Meta attributes by inference: Craft exposes no declarative writable list,
 * so this derives one from validation rules (safeAttributes under the live
 * scenario) minus a generic internal denylist. Per-type overrides only enter
 * here when a test proves inference wrong.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Meta {
    public const array DENYLIST = [
        'id', 'uid', 'siteId', 'siteSettingsId', 'fieldLayoutId', 'contentId', 'canonicalId',
        'dateCreated', 'dateUpdated', 'dateDeleted', 'dateLastMerged', 'draftId', 'revisionId',
        'root', 'lft', 'rgt', 'level', 'structureId', 'searchScore', 'tempId', 'uri',
        // Craft calls these safe, and they are, for Craft. They are not part of
        // the payload contract: an agent addresses them through a named tool
        // argument (section, type, parent) or not at all. Listing them told the
        // agent it could send keys the write tools would reject, which is worse
        // than not mentioning them, because the schema tool is the one thing it
        // is told to trust before writing.
        'sectionId', 'typeId', 'fieldId', 'ownerId', 'primaryOwnerId',
        'sortOrder', 'isFresh', 'placeInStructure', 'authorIds', 'parentId',
    ];

    /**
     * @return string[]
     */
    public function writable(Model $element): array {
        $scenario = $element->getScenario();
        if ($element instanceof Element) {
            $element->setScenario(Element::SCENARIO_LIVE);
        }

        $safe = $element->safeAttributes();
        $element->setScenario($scenario);

        $fieldHandles = $element instanceof Element
            ? array_map(static fn ($field) => (string) $field->handle, $element->getFieldLayout()?->getCustomFields() ?? [])
            : [];

        return array_values(array_diff($safe, self::DENYLIST, $fieldHandles));
    }

    /**
     * @return array{hasTitleField: bool, showSlugField: bool, showStatusField: bool}
     */
    public function entryFlags(EntryType $type): array {
        return [
            'hasTitleField' => $type->hasTitleField,
            'showSlugField' => $type->showSlugField,
            'showStatusField' => $type->showStatusField,
        ];
    }
}
