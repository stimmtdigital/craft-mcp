<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SafeExecution;
use stimmt\craft\Mcp\support\SiteResolver;

/**
 * Entry workflow: publish, delete, duplicate, copy to site.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class EntryWorkflowTools {
    private readonly Reader $reader;

    private readonly Writer $writer;

    public function __construct(?Reader $reader = null, ?Writer $writer = null) {
        $translator = null;
        if ($reader === null || $writer === null) {
            $translator = Craft::$container->has(Translator::class)
                ? Craft::$container->get(Translator::class)
                : Translator::withDefaults();
        }

        $this->reader = $reader ?? new Reader($translator);
        $this->writer = $writer ?? new Writer($translator);
    }

    #[McpTool(
        name: 'publish_entry',
        description: 'Publish an entry: applies a draft to its canonical entry, or enables a disabled live entry.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function publishEntry(int $id, ?string $site = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $site): array {
            $entry = $this->find($id, $site, withDrafts: true);

            if ($entry->getIsDraft()) {
                $applied = Craft::$app->getDrafts()->applyDraft($entry);

                return Response::success(['entry' => $this->reader->read($applied, $site)]);
            }

            $this->enableIfDisabled($entry);

            return Response::success(['entry' => $this->reader->read($entry, $site)]);
        });
    }

    #[McpTool(
        name: 'delete_entry',
        description: 'Soft-delete an entry (moves to trash, restorable in the control panel).',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function deleteEntry(int $id, ?string $site = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $site): array {
            $entry = $this->find($id, $site, withDrafts: true);

            if (!Craft::$app->getElements()->deleteElement($entry)) {
                throw new ToolCallException('Failed to delete entry');
            }

            return Response::success(['deleted' => $id, 'restorable' => true]);
        });
    }

    #[McpTool(
        name: 'duplicate_entry',
        description: 'Duplicate an entry as an unpublished draft. Optional title/slug overrides and a payload-format fields JSON for "like X but change these".',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function duplicateEntry(
        int $id,
        ?string $site = null,
        ?string $title = null,
        ?string $slug = null,
        ?string $fields = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $site, $title, $slug, $fields): array {
            $entry = $this->find($id, $site);

            $attributes = array_filter(['title' => $title, 'slug' => $slug], static fn (?string $v): bool => $v !== null);
            $duplicate = Craft::$app->getElements()->duplicateElement($entry, $attributes, asUnpublishedDraft: true);

            if ($fields !== null) {
                $decoded = json_decode($fields, true);
                if (!is_array($decoded)) {
                    throw new ToolCallException('Invalid JSON in fields parameter');
                }

                $result = $this->writer->update($duplicate, [], $decoded, WriteMode::Draft, $site);
                if ($result->isFailure()) {
                    return ['success' => false] + $result->toArray();
                }
            }

            return Response::success(['entry' => $this->reader->read($duplicate, $site)]);
        });
    }

    #[McpTool(
        name: 'copy_entry_to_site',
        description: 'Copy an entry\'s field values from one site to another as a draft on the target site. Copies values; does not machine-translate.',
    )]
    #[McpToolMeta(category: ToolCategory::MULTISITE, dangerous: true)]
    public function copyEntryToSite(int $id, string $fromSite, string $toSite, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $fromSite, $toSite): array {
            SiteResolver::resolve($fromSite);
            $target = SiteResolver::resolve($toSite);

            $source = $this->find($id, $fromSite);
            $targetEntry = Entry::find()->id($id)->site($toSite)->status(null)->one()
                ?? throw new ToolCallException("Entry {$id} does not exist on site '{$toSite}'; the section may not be enabled for it");

            $payload = $this->reader->read($source, $fromSite);
            $result = $this->writer->update($targetEntry, [], $payload['fields'], WriteMode::Draft, $toSite);

            return $result->isFailure()
                ? ['success' => false] + $result->toArray()
                : Response::success($result->toArray());
        });
    }

    private function enableIfDisabled(Entry $entry): void {
        if ($entry->enabled) {
            return;
        }

        $entry->enabled = true;
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new ToolCallException('Failed to enable entry: ' . json_encode($entry->getErrors()));
        }
    }

    private function find(int $id, ?string $site, bool $withDrafts = false): Entry {
        SiteResolver::resolve($site);

        $query = Entry::find()->id($id)->status(null);
        if ($site !== null) {
            $query->site($site);
        }
        if ($withDrafts) {
            $query->drafts(null);
        }

        return $query->one() ?? throw new ToolCallException("Entry with ID {$id} not found");
    }
}
