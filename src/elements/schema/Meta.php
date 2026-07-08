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
            'hasTitleField' => (bool) $type->hasTitleField,
            'showSlugField' => (bool) $type->showSlugField,
            'showStatusField' => (bool) $type->showStatusField,
        ];
    }
}
