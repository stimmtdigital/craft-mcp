# Smoke harness

Wire level regression guard for the tools the plugin registers into Craft's MCP
server. It drives that server the way a real client does, calls every
`stimmt_` tool, and compares the result against a recorded baseline. It does
that once per **profile**: one identity on one transport.

```bash
php tests/Smoke/fixture.php               # once per install: the content model below
composer smoke                            # every profile, against the baselines
composer smoke -- --profile=http-admin    # one profile (repeatable)
composer smoke:baseline                   # re-record after an intended change
composer smoke -- --heavy                 # include stimmt_backups_create
```

Exit code is 0 only when there is no drift, no unexpected failure and no scope
violation, on every profile that ran.

## What changed in v2

v1 of the plugin was its own MCP server (`bin/mcp-server`, `mcp_` tokens from
`craft mcp/tokens/create`, its own HTTP endpoint), and this harness drove that.
v2 is not a server. Craft 5.12 ships one, and the plugin registers capability
classes into it (`src/integration/Capabilities.php`), every tool prefixed
`stimmt_`. So the harness now drives **Craft's** server and judges only the
plugin's part of it. The v1 harness, its baselines and its manual probes
(`manual/sighup.php`, `manual/wedge.php`, both about v1's own stdio transport)
are in git history.

What Craft decides, and the harness therefore pins:

- **Scope.** A tool with `readOnlyHint: true` needs `mcp:read`; any other tool
  `mcp:write`; a method with `craft\mcp\attributes\RequiresAdmin` needs
  `mcp:admin` and an admin user (`craft\mcp\CapabilityDiscovery::requiredScope`).
  Levels nest: `mcp:admin` satisfies write and read.
- **Registry.** Craft builds the server's registry from the filtered discovery,
  so a tool outside the scope is not hidden, it does not exist for that
  connection: calling it answers JSON-RPC `-32602 Tool not found: "<name>".`
- **Pagination.** `tools/list` pages at 50; the clients follow `nextCursor`.

What the plugin still decides (`src/integration/Gate.php`): its own `enabled`
and `disabledTools` settings, `enableDangerousTools` (off in production), and
its edition. `stimmt_drafts_publish`, `stimmt_entries_copy_to_site`,
`stimmt_nested_entries_create` and `stimmt_nested_entries_move` need the
plugin's Pro edition. The Commerce tools appear only with Commerce installed;
the baseline install has none, so they are not covered.

## Where it runs

The harness runs on the **host** and reaches the Craft install through one
command prefix that runs something in the Craft root:

```bash
export CRAFT_MCP_SMOKE_CRAFT="cd ~/Projects/stimmt/craftcms-headless && ddev exec --dir /var/www/html/apps/backend"
export CRAFT_MCP_SMOKE_ENDPOINT=https://cms.template-project.dev/ai
```

Everything it needs from the install goes through that prefix (`Install.php`):

- **stdio**: `<prefix> php craft mcp/serve --user=<admin email>`, the admin
  found by `inside/console.php info`. `ddev exec` carries a piped stdin and
  stdout, so this is a real stdio session with the server in the container.
- **tokens and the fixture**: the scripts under `inside/` are piped into
  `<prefix> php -- <args>` on stdin. Nothing is copied into the project, so
  nothing needs `ddev mutagen sync`, and a minted token exists only in a pipe
  and in the harness's memory.
- **HTTP**: plain HTTPS from the host to the endpoint. TLS is verified (DDEV's
  mkcert certificate is trusted on the host); export
  `CRAFT_MCP_SMOKE_INSECURE_TLS=1` on an install whose certificate is not.

Without `CRAFT_MCP_SMOKE_CRAFT` the harness walks up from `tests/Smoke` to the
nearest Craft root and uses `cd <root> &&`, so a checkout placed inside a Craft
project (the v1 arrangement, and how it runs inside a container) still works.

**The endpoint override is needed on craftcms-headless.** Without it the harness
asks Craft (`Craft::$app->get('mcp')->getSettings()->url($route)`), and from the
console Craft builds that url on the site's base url, `https://template-project.dev/ai`,
which is the Next.js front end. Craft answers on the CMS host.

### The baseline install

**craftcms-headless**, Stimmt's headless template: Craft 5.12.1 in `apps/backend`,
DDEV project `template-project`, single site (`default`), Craft edition Solo,
plugin edition Pro (project config `plugins.mcp.edition`), Craft's MCP server
enabled on `/ai` with OAuth signing keys generated. The plugin is loaded from
`vendor/stimmt/craft-mcp`, so to test unreleased code sync the checkout over it
first, and remember DDEV syncs through Mutagen:

```bash
cd ~/Projects/stimmt/craftcms-headless
rsync -a --delete ~/Projects/stimmt/craft-mcp/src/ apps/backend/vendor/stimmt/craft-mcp/src/
rsync -a ~/Projects/stimmt/craft-mcp/docs/ apps/backend/vendor/stimmt/craft-mcp/docs/
ddev mutagen sync
```

`docs/` matters: the `stimmt://guides/tools` resource reads `docs/agent-guide.md`.

## The fixture

`fixture.php` installs the content model `Plan.php` is written against, named
for the harness so it cannot collide with a site's own content. The handles
live in `Plan` and are passed to `inside/fixture.php`, which does the work in
the Craft root:

| Plan constant | Handle | What it is |
|---|---|---|
| `SECTION` | `mcpSmoke` | channel section, enabled on every site, URLs at `mcp-smoke/{slug}` so a preview link is minted for real |
| `ENTRY_TYPE` | `mcpSmokePage` | its only entry type |
| `MATRIX_FIELD` | `mcpSmokeBlocks` | Matrix field on that type |
| `BLOCK_TYPE` | `mcpSmokeBlock` | the Matrix field's one block type, no fields, no title field |
| `RELATION_FIELD` | `mcpSmokeRelated` | Entries field on that type, sourced from `mcpSmoke`, for the field-filter count |
| `REQUIRED_FIELD` | `mcpSmokeRequired` | plain text, required in the layout, so a draft that empties it is refused at publish (#76) |
| `FIXTURE_SLUG` | `smoke-fixture` | the one live entry every step works on |

It is idempotent and writes project config, so on a client site keep the
resulting `config/project` files out of that site's commits. v1's fixture also
made a volume, a filesystem, a user group and a time-zoned Date field for the
v1 asset, user and date steps; no v2 tool uses them, so the v2 fixture does
not, and it no longer needs Craft Pro (v1 did, for the user group).

## Why it exists

The unit suite asserts source structure. That is a deliberate trade (booting
Craft is expensive) with a known cost, and v1 paid it repeatedly: an output
layer that fataled on every tool taking no arguments, cached discovery serving
a stale scan, tools that failed on one transport and not the other, all with
`composer ci` green. A green unit suite says the code is shaped as expected.
Only the wire says the tools work, and in v2 only the wire says whether Craft
reads the plugin's annotations the way the plugin meant.

## The profiles

| Profile | Transport | Scopes | Baseline | stimmt tools | Craft tools |
|---|---|---|---|---|---|
| `stdio-admin` | `craft mcp/serve --user=<admin>` | none (stdio carries no token) | `baseline/stdio-admin.json` | 30 | 69 |
| `http-read` | HTTPS, personal access token | `mcp:read` | `baseline/http-read.json` | 4 | 19 |
| `http-write` | HTTPS, personal access token | `mcp:read mcp:write` | `baseline/http-write.json` | 8 | 42 |
| `http-admin` | HTTPS, personal access token | `mcp:read mcp:write mcp:admin` | `baseline/http-admin.json` | 30 | 69 |

Every profile is the same admin user, so what differs between the HTTP
profiles is only the scope, and a difference in their catalogues is Craft's
scope filter reading the plugin's annotations. Every profile runs the same plan
through the same `Client` contract; a step whose tool the connection does not
advertise reports itself `skipped` with that reason.

A profile that cannot connect is reported as `UNREACHABLE` with the reason, and
its baseline is left alone: a harness that records an empty snapshot over a
working guard is worse than no harness.

## Tokens

**The harness mints its own.** Per HTTP profile, `inside/console.php mint`
calls `Craft::$app->getOAuth()->createPersonalAccessToken()` for the install's
first active admin, with exactly the profile's scopes, a one-hour expiry and
the name `smoke-<runId>-<level>`. After the profile, `revoke` revokes it through
Craft's own `craft\oauth\Repositories::revokeAccessToken()` and then deletes
the revoked row by that exact name, which carries the run id, so it can only
reach a token this run created. A run leaves no token behind.

To supply your own instead, export `CRAFT_MCP_SMOKE_TOKEN_READ` (or `_WRITE`,
or `_ADMIN`); a supplied token is used as-is and never revoked.

**No secret reaches a terminal or a file.** `Redact` masks JWTs, bearer values
and `token=` query parameters in every message the harness prints and in every
baseline it writes. Payloads are recorded as shape, so a preview link is
`<string>`. A step marked `secret` (the credential `stimmt_config_get` read and
the steps carrying a preview link) withholds the value from its assertion
failures too.

## The rule

**Every finding becomes a check here before it becomes a fix.**

| The finding is | Put it in | Behaviour |
|---|---|---|
| broken, not fixed yet | `Expectation.php` | pinned to its exact symptom; **fails** when it starts working |
| a value that must hold | `assert` on the step in `Plan.php` | fails when the value stops holding |
| a call that must be turned down | `refusal` on the step in `Plan.php` | fails unless refused, naming the given text |
| a tool a scope must not reach | `Boundary.php` | calls it and fails if it answers |

An entry in `Expectation.php` is self-clearing. It asserts the defect still
reproduces with the same status and the same message, so a fix announces itself
with "no longer reproduces: delete it" instead of passing silently. An entry may
name the profiles it applies to, and is then required to be absent everywhere
else.

A `refusal` step must come back as a tool error (`isError`) whose text names
the given string; it is then recorded as `refused`, without its message, which
carries ids that change every run. A refusal in a form the register knows is
held to that form instead, and must still name the string.

A payload that says `"success": false` without `isError` is its own status,
`unflagged-failure`, because to a client it is a success.

## What it compares

The plugin's tools verbatim (name, title, description, annotations, input
schema), its resources (`stimmt://guides/tools`), Craft's own tools as a count
only, and the **shape** of each payload rather than its values. Shape means
keys plus leaf types, with booleans and nulls kept verbatim because `success`
flipping is the regression we most want to see. Craft's tool count is kept
because it moves if the plugin's Gate ever starts hiding tools that are not its
own; it also moves with a Craft upgrade, which is a re-record.

Two reductions keep the diff honest rather than noisy:

- a map whose keys are all integer-like collapses to one merged value shape,
  because element ids are fresh every run;
- type unions flatten, so a three way disagreement reads `<null|number|string>`.

Values are guarded by assertions instead, where they matter.

A scoped profile also records a `scope` block: the scopes granted, the sorted
`stimmt_` tool names it advertises, the tools probed outside it and how each
call was refused. stdio carries no scope, so it has no such block.

## The scope boundary is tested by calling across it

Hiding a tool from `tools/list` is presentation, and presentation is not a
security boundary. So `Boundary.php` calls the tools each scope must not
reach, with arguments that would succeed if the tool ran:

| Profile | Must not reach |
|---|---|
| `http-read` | `stimmt_drafts_publish` (a write), `stimmt_db_query`, `stimmt_tinker` (admin) |
| `http-write` | `stimmt_db_query`, `stimmt_tinker`, `stimmt_config_get` (admin) |

A probe passes only when the tool is absent from `tools/list` AND the call is
refused with a "tool not found" style message. A tool error from the tool
itself ("Entry 1 not found") is the tool running, and fails. Proved on
2026-11-10 by judging an `mcp:admin` connection as `http-write`: all three
probes came back as violations, advertised and answered.

Across profiles (`Report::ordering`), `http-read`'s `stimmt_` tools must be a
strict subset of `http-write`'s, and those of `http-admin`'s. Each baseline
pins its own set; this catches the filter collapsing so every scope sees
everything, which each baseline would happily re-record as the new normal.

## The plan

Steps per profile on the baseline install (`ok`/`refused`/held, of 42):

| Profile | Run | Skipped |
|---|---|---|
| `stdio-admin` | 41 | 1 (`stimmt_backups_create`, heavy) |
| `http-read` | 8 | 34 (not advertised) |
| `http-write` | 19 | 23 (not advertised) |
| `http-admin` | 41 | 1 (`stimmt_backups_create`, heavy) |

In order: `stimmt_refs_resolve` on the fixture (captures its id for every later
step), `stimmt_entries_count` plain, grouped by section, grouped by site
(captures the primary site handle) and with a relation-field filter,
`stimmt_categories_list`, `stimmt_entries_preview_url` (shape and a url-with-token
pattern only), the `stimmt://guides/tools` resource; the admin reads
(`stimmt_globals_list`, environment, last error, deprecations, console commands,
routes, event handlers, logs, `stimmt_config_get` for `general.devMode` and for
`db.password`, which must come back redacted, `stimmt_tinker` `return 1 + 1;`,
backups list), the database tools, the GraphQL tools (`{ ping }` answers
`pong`); then the writes:

1. Craft's `drafts_create` on the fixture, then `stimmt_drafts_publish` on that
   draft: success, the fixture's id, `draftId` null, `affectedSites`.
2. `stimmt_nested_entries_create` on the fixture (lands on a new draft),
   a second block stacked on that draft, `stimmt_nested_entries_move` of the
   second to position 1, then Craft's `drafts_delete` of the draft, so the
   fixture's live Matrix field stays empty and the positions hold every run.
3. #76: Craft's `drafts_create`, Craft's `elements_update` on the draft id with
   `{"mcpSmokeRequired": ""}` (Craft saves a draft without validating it for
   live content, as the control panel does), `stimmt_drafts_publish` must refuse
   naming `mcpSmokeRequired`, then `drafts_delete`. `elements_update`'s payload
   shape allowed this, so no `stimmt_tinker` fallback was needed.
4. `stimmt_entries_copy_to_site` from the primary site to itself: on a single
   site that is the only copy there is, and the answer is "Nothing to copy".

The publish in step 1 applies an unchanged draft, so every run adds a revision
to the fixture entry and nothing else. Every draft the plan makes is deleted.

## Behaviour this pins

Facts established by running it, recorded so they are not rediscovered:

- **A refusal carries `isError`** with its reasons as structured content:
  the #76 refusal answers `isError: true`, `"success": false`,
  `errors.mcpSmokeRequired: ["MCP smoke required cannot be blank."]`. The first
  v2 run found it arriving without `isError` (1.x's pipeline used to set the
  flag); `Response::failure()` now returns the flagged result itself.
- **`stimmt_entries_copy_to_site` refuses a copy of a site onto itself.**
- **`stimmt_console_commands_list` is stdio only by design**: over HTTP it
  answers `unavailable` with `commands` and `count` null.
- **`stimmt_nested_entries_create` returns `blockId` and `draftElementId`**, and
  passing that `draftElementId` as `owner` stacks the next block onto the same
  draft rather than making another.
- **Craft's `drafts_create` answers `{draft: {...}}`** with the draft's element
  id at `draft.id`; `elements_update` answers `{element, savedAsDraft, instructions}`.
- **Every profile's protocol version is `2025-06-18`.**

## Coverage gaps, deliberately visible

The snapshot carries an `uncovered` list, so a tool with no passing step is a
line in the file rather than an absence. Currently:

- `stimmt_backups_create`: writes a database dump, so it is behind `--heavy`.
- the Commerce tools: Commerce is not installed on the baseline install, so
  they are not registered at all and do not appear in the catalogue.
- a real cross-site `stimmt_entries_copy_to_site`: the baseline install has one
  site.

## Known gaps in the harness itself

1. **Every profile is an admin.** The non-admin axis (an `mcp:admin` token held
   by a non-admin, which Craft strips to write) never engages. Covering it
   needs a second user.
2. **No streaming client.** The harness posts and reads the answer (JSON or
   SSE frames); it does not open a `GET` stream, and session expiry and
   resumption are untested.
3. **One connection at a time.**
4. **Plugin settings are not swept.** `enabled`, `disabledTools` and
   `enableDangerousTools` all change what a connection sees, and all are left
   at this install's values (dangerous tools on: not production).
5. **Values are only checked where an assertion says so.** Everything else is
   shape. `stimmt_logs_read` asks for `error` lines and records an empty list
   on a clean install; the day the install logs an error, its shape changes
   and that profile drifts. That is the install, not the plugin: re-record.
6. **A boundary violation writes.** The `http-read` probe calls
   `stimmt_drafts_publish` on the fixture. On a correct server it is refused
   and nothing happens; on a broken one it is answered (the fixture has no
   pending draft by then, so it refuses for that reason and changes nothing).
