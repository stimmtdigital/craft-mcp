<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use Craft;
use craft\base\ElementInterface;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\refs\Translator;
use Throwable;

/**
 * Agent payload to persisted element. Draft-first: draft mode saves new
 * elements as unpublished drafts and edits live elements through a draft on
 * top, leaving the canonical untouched until publish.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Writer {
    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    public function create(array $attributes, array $fieldsPayload, WriteMode $mode, ?string $site = null): Result {
        $context = new Context($site);
        $element = Craft::$app->getElements()->createElement($attributes);
        if ($site !== null) {
            $targetSite = Craft::$app->getSites()->getSiteByHandle($site);
            $element->siteId = $targetSite === null ? $element->siteId : $targetSite->id;
        }

        $element->setFieldValues($this->prepare($element->getFieldLayout(), $fieldsPayload, $context));

        $saved = $mode === WriteMode::Draft
            ? $this->saveAsDraft($element)
            : Craft::$app->getElements()->saveElement($element);

        return $this->result(Result::ACTION_CREATED, $element, $saved, $mode, $context);
    }

    public function update(ElementInterface $element, array $attributes, array $fieldsPayload, WriteMode $mode, ?string $site = null): Result {
        $context = new Context($site ?? $element->getSite()->handle);

        if ($mode === WriteMode::Draft && !$element->getIsDraft()) {
            $element = Craft::$app->getDrafts()->createDraft($element, (int) (Craft::$app->getUser()->getId() ?? 0));
        }

        Craft::configure($element, $attributes);
        if ($fieldsPayload !== []) {
            $element->setFieldValues($this->prepare($element->getFieldLayout(), $fieldsPayload, $context));
        }

        $saved = Craft::$app->getElements()->saveElement($element);

        return $this->result(Result::ACTION_UPDATED, $element, $saved, $mode, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(?FieldLayout $layout, array $fieldsPayload, Context $context): array {
        return $this->translator->toIds(LayoutFields::of($layout), $fieldsPayload, $context);
    }

    private function saveAsDraft(ElementInterface $element): bool {
        try {
            Craft::$app->getDrafts()->saveElementAsDraft($element, null, null, null, false);

            return !$element->hasErrors();
        } catch (Throwable) {
            return false;
        }
    }

    private function result(string $action, ElementInterface $element, bool $saved, WriteMode $mode, Context $context): Result {
        if (!$saved) {
            return new Result(
                $action,
                null,
                warnings: $context->warnings(),
                errors: $element->getErrors(),
            );
        }

        return new Result(
            $action,
            $element->getCanonicalId(),
            draftId: $element->draftId ?? null,
            state: $element->getIsDraft() ? WriteMode::Draft : WriteMode::Live,
            warnings: $context->warnings(),
            cpEditUrl: $element->getCpEditUrl(),
        );
    }
}
