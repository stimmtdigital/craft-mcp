<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\refs\Translator;

/**
 * Agent payload to persisted element. Draft-first: draft mode saves new
 * elements as unpublished drafts and edits live elements through a draft on
 * top, leaving the canonical untouched until publish.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Writer {
    public function __construct(
        private Translator $translator,
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

        return $this->result(Result::ACTION_CREATED, $element, $saved, $context);
    }

    public function update(ElementInterface $element, array $attributes, array $fieldsPayload, WriteMode $mode, ?string $site = null): Result {
        $context = new Context($site ?? $element->getSite()->handle);

        if ($mode === WriteMode::Draft && !$element->getIsDraft()) {
            $element = Craft::$app->getDrafts()->createDraft($element, Craft::$app->getUser()->getId());
        }

        Craft::configure($element, $attributes);
        if ($fieldsPayload !== []) {
            $element->setFieldValues($this->prepare($element->getFieldLayout(), $fieldsPayload, $context));
        }

        $saved = Craft::$app->getElements()->saveElement($element);

        return $this->result(Result::ACTION_UPDATED, $element, $saved, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(?FieldLayout $layout, array $fieldsPayload, Context $context): array {
        return $this->translator->toIds(LayoutFields::of($layout), $fieldsPayload, $context);
    }

    private function saveAsDraft(ElementInterface $element): bool {
        // Attribute the draft to the acting user (HTTP token identity), so
        // Craft's own-draft permission logic applies to it; identityless
        // runs (stdio console) keep a null creator as before.
        $identity = Craft::$app->getUser()->getIdentity();
        $creatorId = $identity instanceof User ? $identity->id : null;

        // markAsSaved: true. An agent-driven create_entry call is a deliberate
        // act, not a still-typing autosave tick, so it should land where a
        // human's "+ New entry" then "Save draft" click does: saved = 1 in
        // the drafts table. Craft's native create route calls this same
        // method with markAsSaved: false (the "still being composed" state)
        // and relies on the CP's Save button to perform a second save that
        // flips it to true; the plugin has no equivalent second call, and a
        // draft left at false is invisible in the Entries list, search, and
        // the Drafts status filter, for the creator and every other user
        // (#61). It is still an unpublished draft either way: no canonical
        // id, no public URL, safe from public view until publish_entry.
        Craft::$app->getDrafts()->saveElementAsDraft($element, $creatorId, null, null, true);

        return !$element->hasErrors();
    }

    private function result(string $action, ElementInterface $element, bool $saved, Context $context): Result {
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
            draftElementId: $element->getIsDraft() ? $element->id : null,
            state: $element->getIsDraft() ? WriteMode::Draft : WriteMode::Live,
            warnings: $context->warnings(),
            cpEditUrl: $element->getCpEditUrl(),
        );
    }
}
