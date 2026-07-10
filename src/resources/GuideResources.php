<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\resources;

use Mcp\Capability\Attribute\McpResource;
use stimmt\craft\Mcp\attributes\McpResourceMeta;
use stimmt\craft\Mcp\enums\ResourceCategory;

/**
 * Static guides agents can pull on demand: the content-writing contract in
 * full, without bloating the per-connection server instructions.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class GuideResources {
    /**
     * The content-writing payload contract.
     */
    #[McpResource(
        uri: 'craft://guides/content-writing',
        name: 'content-writing-guide',
        description: 'The full content-writing contract for agents: payload format, natural keys, Matrix shape, draft-first workflow, schema discovery with input shapes, and structured feedback.',
        mimeType: 'text/markdown',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONTENT)]
    public function contentWriting(): string {
        return <<<'GUIDE'
# Content Writing Contract

Entry reads and writes share one format: what `get_entry` returns is exactly what `create_entry` and `update_entry` accept. Read an entry, tweak it, write it back.

## Natural keys, no numeric IDs

| Target | Key shape | Example |
|--------|-----------|---------|
| Entries | `{section, slug}` | `{"section": "pages", "slug": "about"}` |
| Assets | `{volume, path?, filename}` | `{"volume": "images", "filename": "hero.jpg"}` |
| Categories / Tags | `{group, slug}` | `{"group": "topics", "slug": "craft"}` |
| Users | `{username}` | `{"username": "anna"}` |
| Global sets | `{handle}` | `{"handle": "footer"}` |

Relation fields take a list of these maps. Numeric IDs are also accepted, but reads always emit keys.

## Matrix blocks

Objects keyed by block id (`new1`, `new2`, ... for new blocks), each with the entry-type handle as `type`, optional `title` and `enabled`, and a `fields` map. Disabled blocks are preserved on round trips.

## Discover the shape first

Call `describe_entry_schema` for the section and entry type before writing. Pass `example` (an entry id or slug) to include a real entry as a golden fixture. Every field carries an `input` shape: the exact payload it accepts (natural-key format for relations, block types for Matrix, allowed values for options, columns for tables, link types for link fields, value types for scalars). When an `input` carries a `note` saying nested values pass through unchanged, send stored IDs or ref strings inside that field, not natural keys.

## Draft-first writes

- `create_entry` saves a draft; `update_entry` on a live entry creates a draft on top, leaving the live version untouched.
- Responses carry `draftElementId` and a `cpEditUrl` deep link for human review in the control panel.
- `publish_entry` applies the draft (canonical id when one pending draft exists, the specific `draftElementId` when several).
- `delete_entry` soft-deletes to the trash; `duplicate_entry` clones as a draft with overrides; `copy_entry_to_site` copies values to another site's version as a draft.
- Pass `mode: "live"` per call (or set the `entryWriteMode` setting) for immediate saves.

## Structured feedback

- Validation failures return per-field errors.
- Unresolvable natural keys become warnings on an otherwise successful save; nothing is guessed, nothing is silently dropped.
- Read the `warnings` list on every write response.

## Multi-site

Read and write tools accept a `site` handle parameter; natural keys resolve within that site.
GUIDE;
    }
}
