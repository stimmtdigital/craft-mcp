<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\elements\Reach;
use stimmt\craft\Mcp\elements\Result;
use stimmt\craft\Mcp\elements\Warning;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\NestedPosition;
use stimmt\craft\Mcp\support\ResourceChangeNotifier;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SiteResolver;
use stimmt\craft\Mcp\support\WriteParams;
use Throwable;

/**
 * Nested Matrix block writes on the owner-draft model: a pending block lives
 * on a draft OF THE OWNER, exactly like a control panel edit, so canonical
 * content and sibling blocks stay untouched until publish_entry applies the
 * owner draft. Never attach a pending block to the canonical owner instead:
 * Craft's owner-save cleanup (NestedElementManager::deleteOtherNestedElements)
 * hard-deletes unpublished draft blocks whose primary owner is the canonical,
 * so any human edit of the owner would silently destroy the block.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class NestedEntryTools {
    private const string ACTION_MOVED = 'moved';

    private readonly Writer $writer;

    public function __construct(?Writer $writer = null) {
        $this->writer = $writer ?? ElementModule::writer();
    }

    #[McpTool(
        name: 'create_nested_entry',
        title: 'Add a Matrix block',
        description: 'Add a block to a Matrix field on an owner entry without resending the field\'s other blocks (the full-field rewrite risks deleting them). In draft mode (default) the block lands on a draft of the owner, like a control panel edit: pass the returned draftElementId to publish_entry to make it live, to get_entry to review it in context, or as owner in follow-up calls to stack more blocks onto the same draft. Optional position places the block among its siblings (1-based, clamped to the end).',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createNestedEntry(
        #[Schema(description: 'Id of the entry that owns the Matrix field: a canonical entry, or a draft of one (pass a draftElementId to stack another block onto the same pending draft).')]
        int $owner,
        #[Schema(description: 'Matrix field handle on the owner\'s field layout; describe_entry_schema reports them.')]
        string $field,
        #[Schema(description: 'Entry type handle the Matrix field allows for its blocks.')]
        string $type,
        #[Schema(description: 'Block title, for block types that have a title field.')]
        ?string $title = null,
        #[Schema(description: 'The block\'s own field values as a JSON-encoded STRING (not a nested object), in the payload format describe_entry_schema documents for that block type.')]
        ?string $fields = null,
        #[Schema(description: 'Site handle to write on, and to report the result in. The block itself is added on every site the owner exists on; only the values of site-translatable fields differ per site.')]
        ?string $site = null,
        #[Schema(description: '"draft" puts the block on a draft of the owner for review, "live" adds it to the canonical owner directly. Omitted follows the entryWriteMode setting, which defaults to draft.')]
        ?string $mode = null,
        #[Schema(description: '1-based slot among the field\'s existing blocks. Omit to append; a position past the end clamps to the last slot.')]
        ?int $position = null,
        ?RequestContext $context = null,
    ): array {
        $this->assertPosition($position);
        $payload = WriteParams::fieldsPayload($fields);
        $writeMode = WriteParams::mode($mode);

        $ownerEntry = $this->ownerFor($owner, $site);
        Authorization::assertCanSave($ownerEntry->getCanonical());

        $matrix = $this->matrixField($ownerEntry->getFieldLayout(), $field);
        $entryType = $this->blockType($matrix, $type);
        $target = $this->target($ownerEntry, $writeMode);
        // Only a draft this call opened is ours to clean up; an owner
        // draft the caller passed in belongs to them and their earlier
        // blocks live on it.
        $opened = $target === $ownerEntry ? null : $target;

        $result = $this->writer->create($this->blockAttributes($entryType, $matrix, $target, $title), $payload, WriteMode::Live, $site);
        if ($result->isFailure()) {
            $this->discard($opened, null);

            return Response::failure($result->toArray());
        }

        $blockId = (int) $result->elementId;

        try {
            $taken = $position === null
                ? $this->endPosition($target, $matrix)
                : NestedPosition::move($target, $matrix, $blockId, $position);
        } catch (Throwable $e) {
            // The block saved but placing it did not: without this the call
            // reports failure while the block sits on the target, so a retry
            // adds a second copy.
            $this->discard($opened, $blockId);

            throw $e;
        }

        $this->notifyOwner($context, $target);

        return Response::success($this->blockResponse(Result::ACTION_CREATED, $blockId, $target, $taken, $result->warnings));
    }

    #[McpTool(
        name: 'move_nested_entry',
        title: 'Reorder a Matrix block',
        description: 'Move a Matrix block to a new 1-based position within its field, by the block\'s own entry id. In draft mode (default) the reorder lands on a draft of the owner entry for review and publish_entry applies it; live mode reorders the canonical directly. Positions past the end clamp to the last slot; the response reports the position actually taken.',
        annotations: new ToolAnnotations(destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function moveNestedEntry(
        #[Schema(description: 'The block\'s own entry id, and the canonical one: a draft or revision copy of a block carries a stale position row.')]
        int $id,
        #[Schema(description: '1-based target slot within the field. A position past the end clamps to the last slot, and the response reports the slot actually taken.')]
        int $position,
        #[Schema(description: 'Site handle to locate the block by. Reordering is not per-site: a block\'s position is shared across sites, so the new order becomes the order on every site the owner exists on.')]
        ?string $site = null,
        #[Schema(description: '"draft" stages the reorder on a draft of the owner entry, "live" reorders the canonical owner directly. Omitted follows the entryWriteMode setting, which defaults to draft.')]
        ?string $mode = null,
        ?RequestContext $context = null,
    ): array {
        $this->assertPosition($position);
        $writeMode = WriteParams::mode($mode);

        $block = $this->blockFor($id, $site);
        $matrix = $this->matrixFieldOf($block);
        $ownerEntry = $this->ownerFor((int) $block->getOwnerId(), $site);
        Authorization::assertCanSave($ownerEntry->getCanonical());

        $target = $this->target($ownerEntry, $writeMode);
        $taken = NestedPosition::move($target, $matrix, (int) $block->id, $position);

        $this->notifyOwner($context, $target);

        return Response::success($this->blockResponse(self::ACTION_MOVED, (int) $block->id, $target, $taken));
    }

    private function assertPosition(?int $position): void {
        if ($position === null || $position >= 1) {
            return;
        }

        throw new ToolCallException("position must be 1 or greater, got {$position}");
    }

    /**
     * Id lookup across every element state: drafts are legal owners (the
     * whole point of the owner-draft model) and revisions must be FOUND so
     * they can be rejected with a message naming the canonical, instead of a
     * blind "not found".
     */
    private function find(int $id, ?string $site): Entry {
        SiteResolver::resolve($site);

        $query = Entry::find()->id($id)->status(null)->drafts(null)->revisions(null);
        if ($site !== null) {
            $query->site($site);
        }

        return $query->one() ?? throw new ToolCallException("Entry {$id} not found");
    }

    private function ownerFor(int $id, ?string $site): Entry {
        $owner = $this->find($id, $site);
        if ($owner->getIsRevision()) {
            throw new ToolCallException(
                "Entry {$id} is a revision; revisions are read-only history and cannot receive or reorder blocks. Target the canonical entry {$owner->getCanonicalId()} instead.",
            );
        }

        return $owner;
    }

    /**
     * The block a move targets, resolved and vetted: it must be a real
     * nested element and the CANONICAL one, because positions live on the
     * canonical block's ownership rows; a draft or revision copy has its own
     * stale row and renumbering it would misreport a move of the visible
     * field.
     */
    private function blockFor(int $id, ?string $site): Entry {
        $block = $this->find($id, $site);

        if ($block->fieldId === null || $block->getOwnerId() === null) {
            throw new ToolCallException(
                "Entry {$id} is not a nested element; move_nested_entry only repositions blocks inside a Matrix field on their owner entry.",
            );
        }

        if ($block->getIsDraft() || $block->getIsRevision()) {
            $kind = $block->getIsDraft() ? 'draft' : 'revision';

            throw new ToolCallException(
                "Entry {$id} is a {$kind} of block {$block->getCanonicalId()}; positions belong to the canonical block. Call move_nested_entry with id {$block->getCanonicalId()}.",
            );
        }

        return $block;
    }

    /**
     * Draft mode targets a draft of the owner, live mode the owner itself.
     * An owner that already IS a draft is reused as-is in both modes (a
     * draft of a draft is illegal in Craft, and a "live" write into a draft
     * owner is still pending by ownership), and the response's state key
     * reports what actually happened.
     */
    private function target(Entry $owner, WriteMode $mode): Entry {
        return $mode === WriteMode::Draft ? $this->draftOf($owner) : $owner;
    }

    /**
     * Undo what this call created before it failed. Deleting the owner draft
     * cascades to a block already attached to it, so the two cases never both
     * apply; without either, a failed create leaves an empty draft in the
     * review queue or a block a retry would duplicate.
     */
    private function discard(?Entry $openedDraft, ?int $blockId): void {
        $elements = Craft::$app->getElements();

        if ($openedDraft instanceof Entry) {
            $elements->deleteElement($openedDraft, true);

            return;
        }

        $block = $blockId === null
            ? null
            : Entry::find()->id($blockId)->drafts(null)->status(null)->one();

        if ($block instanceof Entry) {
            $elements->deleteElement($block, true);
        }
    }

    private function draftOf(Entry $owner): Entry {
        if ($owner->getIsDraft()) {
            return $owner;
        }

        /** @var Entry */
        return Craft::$app->getDrafts()->createDraft($owner, Craft::$app->getUser()->getId());
    }

    /**
     * The Matrix field $handle names on the owner's layout. Pure lookup, so
     * unknown handles can answer with the handles that WOULD work.
     */
    private function matrixField(?FieldLayout $layout, string $handle): Matrix {
        $available = [];
        foreach ($layout?->getCustomFields() ?? [] as $field) {
            if ($field instanceof Matrix) {
                $available[$field->handle] = $field;
            }
        }

        if (isset($available[$handle])) {
            return $available[$handle];
        }

        $handles = $available === [] ? '(none)' : implode(', ', array_keys($available));

        throw new ToolCallException(
            "Field '{$handle}' is not a Matrix field on this entry. Matrix fields available: {$handles}",
        );
    }

    /**
     * The field an existing block lives in. A CKEditor-nested entry has no
     * positional meaning (its rendered order is markup-driven), so anything
     * that is not a Matrix field refuses rather than renumbering rows the
     * page never reads.
     */
    private function matrixFieldOf(Entry $block): Matrix {
        try {
            $field = $block->getField();
        } catch (Throwable) {
            $field = null;
        }

        if ($field === null) {
            throw new ToolCallException("Block {$block->id} references a field that no longer exists; it cannot be repositioned.");
        }

        if (!$field instanceof Matrix) {
            throw new ToolCallException(
                "Block {$block->id} lives in field '{$field->handle}', which is not a Matrix field; only Matrix blocks have a position to move.",
            );
        }

        return $field;
    }

    private function blockType(Matrix $field, string $handle): EntryType {
        foreach ($field->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        $handles = implode(', ', array_map(static fn (EntryType $type): string => (string) $type->handle, $field->getEntryTypes()));

        throw new ToolCallException("Entry type '{$handle}' is not allowed in Matrix field '{$field->handle}'. Allowed types: {$handles}");
    }

    /**
     * The block is saved LIVE even in draft mode: its pending-ness comes
     * from being owned by the owner draft, which is exactly how the control
     * panel models a block added inside a draft. Saving it as a draft
     * element instead would make it its own canonical and expose it to
     * Craft's canonical-owner cleanup sweeps.
     *
     * @return array<string, mixed>
     */
    private function blockAttributes(EntryType $entryType, Matrix $field, Entry $target, ?string $title): array {
        $attributes = [
            'type' => Entry::class,
            'typeId' => $entryType->id,
            'fieldId' => $field->id,
            'ownerId' => $target->id,
            'primaryOwnerId' => $target->id,
            'siteId' => $target->siteId,
        ];

        if ($title !== null) {
            $attributes['title'] = $title;
        }

        return $attributes;
    }

    /**
     * The 1-based position a block appended without an explicit position
     * ends up in: the last slot. Counted from the target's own field value
     * rather than echoing the stored sortOrder, which trashed and draft
     * sibling rows inflate past the visible block count. Counts the query
     * itself, never a clone: cloning a yii Component detaches the behavior
     * that scopes the query to this owner at prepare time.
     */
    private function endPosition(Entry $target, Matrix $field): int {
        $value = $target->getFieldValue($field->handle);
        if (!$value instanceof ElementQueryInterface) {
            throw new ToolCallException(
                "Field '{$field->handle}' on entry {$target->id} did not return a block query; cannot report the block position.",
            );
        }

        return (int) $value->status(null)->count();
    }

    /**
     * A draft-mode write never touches what craft://entries/{section}/{slug}
     * serves, so only a change to a non-draft target notifies subscribers.
     */
    private function notifyOwner(?RequestContext $context, Entry $target): void {
        if ($target->getIsDraft()) {
            return;
        }

        ResourceChangeNotifier::notifyEntry($context, (int) $target->getCanonicalId());
    }

    /**
     * The standard write-response shape as far as it fits a nested write:
     * the draft keys describe the OWNER draft (draftElementId is what
     * publish_entry accepts), blockId/ownerId/position locate the block
     * itself, and cpEditUrl deep-links the human to the target owner where
     * the block is visible in context.
     *
     * @param Warning[] $warnings
     * @return array<string, mixed>
     */
    private function blockResponse(string $action, int $blockId, Entry $target, int $position, array $warnings = []): array {
        $isDraft = $target->getIsDraft();

        return [
            'action' => $action,
            'blockId' => $blockId,
            'ownerId' => (int) $target->getCanonicalId(),
            'draftId' => $isDraft ? $target->draftId : null,
            'draftElementId' => $isDraft ? (int) $target->id : null,
            'state' => $isDraft ? WriteMode::Draft->value : WriteMode::Live->value,
            'position' => $position,
            // A block's presence and its position are shared: elements_owners
            // is keyed by block and owner with no site column, so adding or
            // moving one lands on every site the owner exists on. Only the
            // values of site-translatable fields differ per site.
            'affectedSites' => Reach::of($target),
            'cpEditUrl' => $target->getCpEditUrl(),
            'warnings' => array_map(static fn (Warning $warning): array => $warning->toArray(), $warnings),
            'errors' => [],
        ];
    }
}
