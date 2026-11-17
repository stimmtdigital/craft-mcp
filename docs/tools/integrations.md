# Plugin Integration Tools

Reads for three widely used third-party plugins: Retour's 404 statistics, SEOmatic's resolved SEO metadata, and Formie's failed form deliveries. Craft's own MCP server has no tools for any of them.

All three only read. Lite edition. None is dangerous.

| Tool | Plugin | Scope | Edition |
|------|--------|-------|---------|
| [`stimmt_redirects_404s`](#stimmt_redirects_404s) | Retour 5 | read | Lite |
| [`stimmt_seo_gaps`](#stimmt_seo_gaps) | SEOmatic 5 | read | Lite |
| [`stimmt_forms_failures`](#stimmt_forms_failures) | Formie 4 | admin | Lite |

## When they appear

Each tool is registered only when its plugin is installed, **enabled**, and on the version line the tool was written against. Otherwise the tool does not exist on the server at all, so a client never sees it.

| Tool | Registered when |
|------|-----------------|
| `stimmt_redirects_404s` | Retour is enabled at version 5.0.0 or later |
| `stimmt_seo_gaps` | SEOmatic is enabled at version 5.0.0 or later |
| `stimmt_forms_failures` | Formie is enabled at version 4.0.0 or later **and** Formie's delivery attempts table exists. A site that updated Formie but has not run its migrations yet does not get the tool until `php craft up` has run |

Craft Commerce tools are registered the same way, whenever Commerce is installed and enabled; see [Commerce](commerce.md).

Each tool refuses a response larger than `maxResponseBytes` (see [Configuration](../configuration.md)).

---

## stimmt_redirects_404s

Retour's 404 statistics, most-hit first: the missing URL, how often it was hit, where visitors came from, whether a redirect handles it, and a suggested existing entry.

Example question: *"Which broken URLs do visitors hit most, and where should they redirect?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `site` | string | null | Site handle. Omit for 404s on every site |
| `days` | int | 30 | Only URLs last hit in the past N days, 1 to 3650 |
| `limit` | int | 20 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip, 0 or more |
| `excludeHandled` | bool | true | Skip URLs a redirect already handles. Pass `false` to audit those too |

Returns the paginated envelope, plus `since` (ISO 8601, UTC) and `notes`, and `urls`, each:

| Key | Meaning |
|-----|---------|
| `url` | The missing URL as Retour stored it |
| `hits` | Retour's **lifetime** hit count for the URL. `days` filters on the most recent hit, not on the count |
| `lastHit` | ISO 8601, UTC |
| `referrer` | The last referrer, cut to its path: query string and fragment removed. `null` when none |
| `handled` | `true` when Retour redirected the last hit, or an enabled exact-match static redirect now exists for the path (on that site or all sites). Regex redirects are not evaluated |
| `suggestion` | Optional. `entryId`, `title`, `url` and `basis` of a live entry on the same site whose slug resembles the URL's last segment |

A suggestion is a guess by slug similarity, labelled as one in `basis`. It is never a redirect target to apply without checking the content. Suggestions are computed for the first 25 rows of a page only, search entries the user may view on the 404's own site, and appear only when the similarity is high enough; a URL whose last segment has no word of three or more letters gets none.

Not admin-only: a 404 URL is public by nature, and the referrer, the one field that can carry a visitor's data, is cut to its path. This tool does not create redirects.

---

## stimmt_seo_gaps

Scan live entries for SEO metadata as SEOmatic resolves it, and list the entries with issues.

Example question: *"Which blog posts are missing a meta description or a social sharing image?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `section` | string | null | Section handle. Omit to scan entries from every section that has URLs |
| `site` | string | null | Site handle. Omit for Craft's primary site |
| `limit` | int | 25 | How many entries to **scan**, not how many to return. 1 to 50 |
| `offset` | int | 0 | Entries to skip before scanning, 0 or more |

The values are the ones SEOmatic itself resolves for each entry: the global settings, the section's settings and the entry's SEO Settings field merged, with their templates parsed. They are read after parsing and before SEOmatic's render-time truncation. The length limits are SEOmatic's own `maxTitleLength` and `maxDescriptionLength`, so the result agrees with the warnings in SEOmatic's control panel.

Issues:

| Issue | Severity | Meaning |
|-------|----------|---------|
| `missing_description` | error | No meta description |
| `missing_og_image` | error | No og:image for social sharing |
| `title_too_long` | warning | The SEO title, without the site name SEOmatic appends, is over `maxTitleLength`, so it is truncated when rendered |
| `description_too_long` | warning | Over `maxDescriptionLength` |
| `noindex_on_live_entry` | flag | `robots` holds `noindex` or `none` on a live entry. Worth a second look, not necessarily wrong |
| `title_render_error`, `description_render_error` | error | SEOmatic could not render the template for that value |
| `meta_unresolved` | error | SEOmatic could not resolve the entry at all; `detail` holds its error, scrubbed of email addresses and phone numbers and cut at 500 characters |

A missing title is not reported: SEOmatic falls back to the site name, so a page always has one.

Returns the paginated envelope with `entries` (`id`, `title`, `section`, `url`, `issues`, where each issue is `{issue, detail, severity}`), plus `scanned`, `basis` (`seomatic`), `maxTitleLength`, `maxDescriptionLength` and `notes`. Only entries with issues are listed. Paging works on entries scanned: `total` counts entries in scope, and `hasMore` says whether to call again at `offset + scanned`.

Bound: at most 50 entries per call, because each entry runs SEOmatic's full meta resolution, Twig included. Only entries the user may view are scanned.

---

## stimmt_forms_failures

Formie submissions whose integrations (CRM, webhook, email marketing) or email notifications failed, newest first, with the error each ended on.

Example question: *"Did any contact form submissions fail to reach the CRM this month?"*

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `form` | string | null | Form handle. Omit for every form. An unknown handle is refused with the handles that exist |
| `days` | int | 30 | Only failures from the last N days, 1 to 3650 |
| `limit` | int | 20 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip, 0 or more |

Returns the paginated envelope, plus `since` (ISO 8601, UTC), and `failures`, each:

| Key | Meaning |
|-----|---------|
| `submissionId` | The Formie submission |
| `form` | Form handle |
| `integration` | The integration's or notification's name |
| `kind` | `integration` or `notification` |
| `status` | Always `failed` (Formie reads its retired `rejected` and `unknown` statuses as failed too) |
| `error` | The error message, at most 500 characters |
| `dateFailed` | ISO 8601, UTC |
| `retryable` | Always `true`: Formie 4 can retry any failure from the submission's page |

A failure that a later attempt of the same delivery retried successfully is left out.

Admin-only, because every row is a submission and so a person. The tool returns ids, the form, the integration and the error text, **never a submitted value**: the attempt's sent data, the response and diagnostics are not read into the result. CRMs and mail providers often echo the rejected record in their errors, so email addresses and phone numbers in the error text are replaced with `[email]` and `[phone]`. That is a best-effort filter, not an anonymiser. When Formie stored only a truncated result, the error says so instead of showing the cut-off payload.

The tool only reads. Retry from the submission in Formie's control panel.
