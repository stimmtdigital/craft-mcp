# Content Writing for Agents

> **Since 1.4.0.** The payload format, draft-first writes, the workflow tools, and per-field input shapes all require Craft MCP 1.4.0 or later. Earlier versions accept numeric IDs and save writes live immediately.

Entry reads and writes share one format, powered by an element-generic `elements` module. What `get_entry` returns is exactly what `create_entry` and `update_entry` accept, so an agent can read an entry, tweak it, and write it back without translation. This page explains the format and the workflow around it.

## The Payload Format

### Natural keys, no numeric IDs

Relational field values identify elements by things humans and agents actually know:

| Target | Key shape | Example |
|--------|-----------|---------|
| Entries | `{section, slug}` | `{"section": "pages", "slug": "about"}` |
| Assets | `{volume, path?, filename}` | `{"volume": "images", "filename": "hero.jpg"}` |
| Categories / Tags | `{group, slug}` | `{"group": "topics", "slug": "craft"}` |
| Users | `{username}` | `{"username": "anna"}` |
| Global sets | `{handle}` | `{"handle": "footer"}` |

Relation fields take a list of these maps. Numeric IDs are also accepted anywhere a key is, but reads always emit keys.

### Matrix in core shape

Matrix values are objects keyed by block id (use `new1`, `new2`, ... for new blocks), each with the entry-type `type` handle, optional `title` and `enabled`, and a `fields` map:

```json
{
  "contentBuilder": {
    "new1": {
      "type": "contentBlock",
      "enabled": true,
      "fields": {
        "contentExtensive": "<h2>Hello</h2><p>Rich text is an HTML string.</p>"
      }
    }
  }
}
```

Disabled blocks are preserved on round trips, never silently dropped.

### Editing one block directly

The block key in the Matrix object above (`new1` in that example) is a placeholder only for a block that doesn't exist yet. For a block `get_entry` already returned, that same key is the block's own entry id, since every Matrix-family block is a real, independently-addressable entry in Craft 5, not a row inside the owner's field.

That id works directly with the entry tools, same as any other entry id:

- `update_entry id=<blockId> fields='{...}'` edits that one block. Sibling blocks and the owner's own field value are untouched.
- `delete_entry id=<blockId>` removes that one block.
- `publish_entry id=<blockId>` applies that block's own pending draft in place.

This is the safer default for a single-block change. Sending the owner's Matrix field through `update_entry` replaces the field's entire value; any block left out of the payload is deleted. Targeting a block's own id skips that risk entirely, since the owner's field value and every sibling block are never touched.

One limit: this only reaches a block whose type already appears somewhere in the field. Adding the first-ever block of a brand new type still needs the full owner-field payload.

## Discover the Shape First: describe_entry_schema

Call `describe_entry_schema` for a section and entry type before writing. It returns everything needed to construct a valid entry on the first attempt:

- every field with its handle, kind, required flag, and instructions
- Matrix block types, depth-expanded into their sub-fields
- native attributes (title and friends) and writable meta attributes
- an optional real entry as a golden-fixture example (pass `example` with an entry id or slug)
- a per-field `input` shape: the exact payload that field accepts

The `input` shape is the key to third-party fields. Kinds and what their input looks like:

| Kind | Input |
|------|-------|
| `scalar` | Plain value; `valueType` names the PHP type, with hints for dates (ISO 8601 string) and rich-text fields (HTML string) |
| `relation` | List of natural-key maps; `item` gives the key shape, `elementType` the target |
| `matrix` | Block objects as above; `blockTypes` expands each type's sub-fields |
| `link` | Single link object for the core Link field; `types` maps each configured type to its key shape |
| `links` | List of link objects (Hyper-style fields); `linkTypes` expands each type's native attributes and custom sub-fields; put native attributes at the link object's top level and custom sub-fields under a nested `fields` object |
| `options` | One (or a list, when `multiple`) of `allowedValues` |
| `table` | List of row objects keyed by column handle; `columns` describes them |
| `container` | Object (or list of objects) with a `fields` map |
| `object` | Third-party field backed by its own field layout; a `fields` map of its sub-fields |
| `nested` | Depth limit reached; call again with a higher `depth` |

Shapes whose parent field the writer does not translate (Hyper-style links, generic third-party fields) carry a `note` saying nested relation values there take stored IDs or ref strings, not natural keys. Trust the note.

## Draft-First Writes

By default every write lands as a draft:

- `create_entry` saves a new entry as a draft; `update_entry` on a live entry creates a draft on top, leaving the live version untouched.
- The response carries `draftId`, `draftElementId`, and a `cpEditUrl` deep link so a human can review in the control panel.
- `publish_entry` applies the draft (pass the canonical entry id when there is a single pending draft, or the specific `draftElementId` when there are several).
- Set `entryWriteMode: 'live'` in `config/mcp.php`, or pass `mode: "live"` per call, for immediate saves.

The rest of the workflow: `list_drafts` lists the pending review queue (newest first, filterable by section, site, or creator), `delete_entry` soft-deletes to the trash (restorable), `duplicate_entry` clones as a draft with optional overrides ("like X but change these"), and `copy_entry_to_site` copies field values to another site's version as a draft for localization work.

## Structured Feedback

- Validation failures return per-field errors, not a generic failure.
- A natural key that resolves to nothing becomes a warning on an otherwise successful save; nothing is guessed and nothing is silently dropped.
- Read the `warnings` list on every write response.

## Third-Party Fields

Any field type round-trips through Craft's own serialize contract. Fields extending the core relation or Matrix types get natural keys automatically. Fields that embed element IDs in their own formats can register a translator via the `EVENT_REGISTER_FIELD_TRANSLATORS` event; see the [Extending guide](extending.md).

## Site Awareness

Read and write tools accept a `site` handle parameter. Natural keys resolve within that site, and `copy_entry_to_site` moves content between sites explicitly.
