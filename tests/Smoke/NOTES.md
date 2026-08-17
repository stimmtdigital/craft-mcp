# Smoke harness

Wire level regression guard. It drives the server the way a real client does,
calls every tool, and compares the result against a recorded baseline. It does
that once per **profile**: one identity on one transport.

```bash
ddev exec bash -c "cd /var/www/html/backend/plugins/craft-mcp && composer smoke"
composer smoke -- --profile=http-full   # one profile
composer smoke:baseline                 # re-record after an intended change
composer smoke:heavy                    # include create_backup and anything else slow
```

Exit code is 0 only when there is no drift, no unexpected failure and no scope
violation, on every profile that ran.

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
- `reload_mcp` destroys its own response over HTTP, on every call. Green suite,
  and invisible to a harness that only speaks stdio.

A green unit suite says the code is shaped as expected. Only the wire says the
tools work.

## The profiles

| Profile | Transport | Scope | Baseline | Tools |
|---|---|---|---|---|
| `stdio-full` | `bin/mcp-server` over stdio | none (stdio carries no scope) | `baseline/stdio.json` | 55 |
| `http-full` | the `httpPath` endpoint | `full` | `baseline/http-full.json` | 55 |
| `http-content` | the `httpPath` endpoint | `content` | `baseline/http-content.json` | 50 |
| `http-readonly` | the `httpPath` endpoint | `readonly` | `baseline/http-readonly.json` | 42 |

Every profile runs the same plan through the same `Client` contract, so a
difference in results is a difference in the server rather than in the harness.
A profile that cannot connect is reported as `UNREACHABLE` with the reason, and
its baseline is left alone: a harness that records an empty snapshot over a
working guard is worse than no harness.

## Running the HTTP profiles

They need the HTTP transport switched on (`httpTransport`, plus the `httpPath`
the endpoint answers on) and a bearer token. **The harness mints its own**: it
asks Craft for an admin (`craft users/list-admins`), mints a token of the
profile's scope through the plugin's own console command, reads the endpoint url
out of the client snippet that command prints, uses the token for one run, and
revokes it afterwards. Nothing durable is created and no token is ever written
to a snapshot, a log, or any file.

To supply your own instead, export both of:

```bash
set -x CRAFT_MCP_SMOKE_TOKEN_FULL mcp_...        # or _CONTENT, or _READONLY
set -x CRAFT_MCP_SMOKE_ENDPOINT https://cms.example.com/mcp-http
```

A supplied token is used as-is and never revoked. `CRAFT_MCP_SMOKE_ENDPOINT` is
also the fallback when `showClientConfigSnippet` is off and the console command
prints no url.

## The rule

**Every finding becomes a check here before it becomes a fix.**

Three mechanisms, and the choice between them is mechanical:

| The finding is | Put it in | Behaviour |
|---|---|---|
| broken, not fixed yet | `Expectation.php` | pinned to its exact symptom; **fails** when it starts working |
| a value that must hold | `assert` on the step in `Plan.php` | fails when the value stops holding |
| a tool a scope must refuse | `Boundary.php` | calls it and fails if it answers |

An entry in `Expectation.php` is self-clearing. It asserts the defect still
reproduces with the same status and the same message, so a fix announces itself
with "no longer reproduces: delete it" instead of passing silently. That makes
the register a todo list the machine maintains.

An entry may name the profiles it applies to, and then it is required to be
absent everywhere else. That is not a convenience: both GraphQL tools fail on
stdio and answer normally over HTTP, and a register that could not say so would
have to either forgive a real failure over HTTP or demand a fictional one.

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

A scoped profile also records a `scope` block: the tools it advertises, the
tools probed outside its scope, and how each of those calls was refused. stdio
carries no scope at all, so it has no such block rather than a null one.

## The scope boundary is tested by calling across it

Hiding a tool from `tools/list` is presentation, and presentation is not a
security boundary. A readonly connection that omits `tinker` from its listing
but still runs it when asked by name is a privilege escalation, and it looks
identical to a correct server in any snapshot that only records what was
advertised. So `Boundary.php` calls the tools each scope must refuse, with
arguments that would succeed if the tool ran, and a result instead of a refusal
fails the run.

Two things are asserted, and they catch different failures:

- each profile's tool count is pinned by its own baseline, so a count that moves
  is drift;
- across profiles, a narrower scope must reach **strictly fewer** tools than a
  wider one, which catches the filter collapsing so that every scope sees
  everything, something each baseline would happily re-record as the new normal.

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
- The tool catalogue is **55 tools** on a full scope connection, on both
  transports: 50 under `content`, 42 under `readonly`.
- **A tool outside the scope is refused, not merely hidden.** Every probe comes
  back as JSON-RPC `-32601`, from the SDK registry rather than from a tool.
- **Both GraphQL tools work over HTTP** and fail on stdio. The transport, not
  the tool, is the broken thing.
- **`reload_mcp` destroys its own response over HTTP**, on every call. See the
  register.

## Coverage gaps, deliberately visible

The snapshot carries an `uncovered` list, so a tool with no step is a line in
the file rather than an absence. A tool added without a step also shows up as
drift in the catalogue, so coverage cannot rot quietly.

Currently uncovered and why:

- `copy_entry_to_site`: needs a second site; this install has one.
- `create_backup`: writes a database dump, so it is behind `--heavy`.
- `get_asset`: no assets exist on this install to address.
- `query_graphql`, `execute_graphql`: uncovered on stdio only, where they are
  broken; both are covered on the HTTP profiles.
- `reload_mcp`: uncovered on the HTTP profiles only, where it is broken.

## Known gaps in the harness itself

Stated so nobody mistakes a pass for more than it is:

1. **Every profile runs as an admin.** The privileged axis (install
   introspection hidden from *non-admin* readonly and content tokens, opened
   again by `scopedTokenPrivilegedTools`) never engages, because an admin is
   allowed all of it. Covering it needs a second, non-admin user.
2. **One suspension path of five.** `reload_mcp` notifies unconditionally and is
   the only thing here that provokes a fiber suspension. Progress reporting
   suspends only when the client sends a `progressToken`, client logging only at
   or below the negotiated level, and resource subscriptions only with an active
   subscription. The harness sends none of those, so those three paths are
   untested on both transports.
3. **No streaming client.** The endpoint refuses `GET` by design (no SSE under
   FPM in v1), and the harness does not ask for one. Session expiry and session
   resumption are likewise untested; the harness opens a session, uses it, and
   ends it with `DELETE`.
4. **One connection at a time.** Nothing here exercises two clients sharing a
   session, or a second request arriving while a first is in flight.
5. **Transport settings are not swept.** `allowedIps`, `disabledScopes`,
   `disabledTools` and `paginationLimit` all change what a connection sees, and
   all are left at this install's values.
6. **The plan is install-specific.** Section, entry type and Matrix field are
   constants at the top of `Plan.php`. On another install the content steps fail
   loudly rather than skipping quietly, which is the intended trade.
7. **Values are only checked where an assertion says so.** Everything else is
   shape.
8. **A boundary violation writes.** The readonly probe calls `create_entry` with
   arguments that would work. On a correct server it is refused and nothing
   happens; on a broken one the run leaves a draft behind, which is the cost of
   proving the boundary by crossing it rather than by reading a list.
