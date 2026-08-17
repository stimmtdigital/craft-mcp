# Smoke harness

Wire level regression guard. It drives `bin/mcp-server` over stdio exactly as a
real client does, calls every tool, and compares the result against a recorded
baseline.

```bash
ddev exec bash -c "cd /var/www/html/backend/plugins/craft-mcp && composer smoke"
composer smoke:baseline   # re-record after an intended change
composer smoke:heavy      # include create_backup and anything else slow
```

Exit code is 0 only when there is no drift and no unexpected failure.

## Why it exists

The unit suite asserts source structure. Of 87 test files, 11 boot a real Craft
app and 2 touch the wire. That is a deliberate trade (booting Craft is
expensive) with a known cost, and we have paid it:

- The output layer once fataled on every tool that takes no arguments, breaking
  19 of 53 tools, while `composer ci` stayed green. The fixture built the empty
  property map as `[]`, a shape the SDK never produces.
- Cached tool discovery served a stale scan across reconnects and container
  restarts. Green suite.
- `reload_mcp` returned a generic internal error for weeks. Green suite.
- `query_graphql` and `execute_graphql` fail on stdio. Green suite, and none of
  it showed up in code review either.

A green unit suite says the code is shaped as expected. Only the wire says the
tools work.

## The rule

**Every finding becomes a check here before it becomes a fix.**

Two mechanisms, and the choice between them is mechanical:

| The finding is | Put it in | Behaviour |
|---|---|---|
| broken, not fixed yet | `Expectations.php` | pinned to its exact symptom; **fails** when it starts working |
| a value that must hold | `assert` on the step in `Plan.php` | fails when the value stops holding |

An entry in `Expectations.php` is self-clearing. It asserts the defect still
reproduces with the same status and the same message, so a fix announces itself
with "no longer reproduces: delete it" instead of passing silently. That makes
the register a todo list the machine maintains.

Assertions exist because a shape diff is blind to emptiness: a `list_entries`
that starts returning zero entries has exactly the shape it had yesterday.

## What it compares

The tool catalogue verbatim (names, descriptions, annotations, schemas), and
the **shape** of each payload rather than its values. Shape means keys plus leaf
types, with booleans and nulls kept verbatim because `success` flipping is the
regression we most want to see.

Two reductions keep the diff honest rather than noisy:

- a map whose keys are all integer-like collapses to one merged value shape,
  because Matrix blocks are keyed by block id and those ids are fresh every run;
- type unions flatten, so a three way disagreement reads `<null|number|string>`
  rather than nesting.

Values are guarded by assertions instead, where they matter.

## Behaviour this pins

Facts established by running it, recorded so they are not rediscovered:

- **`duplicate_entry` answers with a whole `entry` object**, where `create_entry`
  and `update_entry` answer with `draftElementId`. Two response shapes across the
  write tools. Not a bug, but an agent has to special case it.
- **`create_nested_entry` returns `blockId`**, not `id`.
- **`publish_entry` returns `{entry, success}`** with `entry.draftId` null.
- **`describe_entry_schema`'s `example` takes a string** and must name an entry
  in the section being described, or it answers "Entry not found".
- **`list_entries.fields` is an array**, not a comma separated string.
- The tool catalogue is **55 tools** on a full scope stdio connection.

## Coverage gaps, deliberately visible

The snapshot carries an `uncovered` list, so a tool with no step is a line in
the file rather than an absence. A tool added without a step also shows up as
drift in the catalogue, so coverage cannot rot quietly.

Currently uncovered and why:

- `copy_entry_to_site`: needs a second site; this install has one.
- `create_backup`: writes a database dump, so it is behind `--heavy`.
- `get_asset`: no assets exist on this install to address.
- `query_graphql`, `execute_graphql`: broken on stdio, see `Expectations.php`.

## Known gaps in the harness itself

Stated so nobody mistakes a pass for more than it is:

1. **One scope, one transport.** It runs as an admin over stdio. The HTTP
   transport and the read-only and content scopes are untested, and the worst
   known SDK defect (a tool that notifies over HTTP destroys its own response)
   lives on the transport this cannot see.
2. **The plan is install-specific.** Section, entry type and Matrix field are
   constants at the top of `Plan.php`. On another install the content steps fail
   loudly rather than skipping quietly, which is the intended trade.
3. **Values are only checked where an assertion says so.** Everything else is
   shape.
