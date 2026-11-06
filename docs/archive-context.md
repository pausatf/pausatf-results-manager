# Archive context operator guide

The archive workflow stores original result context as immutable candidates, requires a different administrator to verify each candidate, and publishes only explicitly approved versions. It uses additive tables and does not import candidates into the existing results or athlete tables.

This implementation has been exercised with isolated fixtures. These instructions do not establish production deployment, a completed archive migration, or verified historical ruleset applicability.

## Prepare the environment and evidence

Use the supported PHP runtime and WordPress installation with its existing native `mysqli` connection, `utf8mb4` session charset, and MySQL transactional tables. The archive adapter does not open another connection or reconnect automatically. Installation and writes reject caller-owned transactions and read-only sessions. Schema installation verifies the actual columns, indexes, foreign keys, engine and collation before recording its version; incompatible existing tables require investigation, not automatic repair.

Before a separately authorized deployment, retain a database backup and the exact plugin revision, and verify restoration in an isolated environment. Keep original source files, bundles and verification reports in the archive's controlled evidence storage. The database retains original bundle and report JSON plus source checksum, byte count and logical path; it does **not** retain the uploaded original source bytes.

All administration requires the WordPress `manage_options` capability. Use two distinct existing administrators for production and independent review. CLI commands require `--user` to select an authorized WordPress account; the system shell user alone does not supply that authorization.

## Install and stage

In **PAUSATF Results → Archive review**, choose **Install archive tables**. Installation is explicit and does not run automatically on plugin activation. Then upload a context bundle and its original source using **Stage candidate**.

Equivalent CLI commands, run from the WordPress installation:

```bash
wp --user=producer-admin pausatf-results archive install
wp --user=producer-admin pausatf-results archive stage /archive/candidate.json --source=/archive/original.txt
wp --user=producer-admin pausatf-results archive inspect 123
```

Replace account names, paths and `123` with actual values. Staging returns the release ID, bundle checksum and state. Resubmitting the exact same bundle bytes returns the existing release; it does not create a new version. Even JSON whitespace changes produce a different bundle checksum.

The annotated [two-line fixture](../tests/fixtures/archive-context/1996-5ka-bundle.json) demonstrates the bundle shape. Its [provenance notes](../tests/fixtures/archive-context/README.txt) distinguish original text from synthetic test variations. It is an excerpt, not a complete historical result document.

Bundles use `schema_version: 1`, a logical `competition_key`, source metadata, the entity collections, and source coverage entries. The eleven result collections (`rulesets`, `competitions`, `divisions`, `rounds`, `performances`, `attempts`, `rankings`, `teams`, `scores`, `result_releases`, `record_claims`) are required. The `series_standings` and `association_records` collections are optional: a bundle that omits them, including every bundle staged before archive schema version 2, is treated as having none. When included, each must be a JSON list. Unknown collection names are rejected. Every record needs a unique key within its collection and a source locator. Every record must occur once in coverage; excluded source portions need reasons. The reviewer must still compare completeness against the actual source: the workflow does not infer every row that a source ought to contain.

Current limits and restrictions:

| Input | Limit or behavior |
| --- | --- |
| Bundle JSON or review JSON | 8 MiB; valid UTF-8 JSON with maximum nesting depth 64 |
| Original source | 32 MiB; checksum and byte count must match the bundle |
| Entity records | 5,000 total across all collections |
| Entity key | 1–96 ASCII letters, digits, underscores, periods, colons or hyphens; first character alphanumeric |
| Declared source format | `html`, `text`, `pdf`, `ole`, `zip`, or `elf` |
| Binary source | Actual signature must match; only ruleset, competition and result-release metadata are accepted (no standings or records rows) |
| Printed text fields | Labels, names, clubs, marks and meets at most 255 characters; places, totals, printed dates and race points at most 64; records status at most 96 |
| Race points per standings row | JSON object of at most 60 printed race labels (each at most 96 characters) to printed strings |
| Public collection page | At most 100 records; page number at most 100,000 |

WordPress, PHP and the web server can impose smaller upload/request limits. Binary metadata staging does not execute, unpack, render or extract the uploaded file. Structured extraction needs a separately verified conversion and its own source evidence.

Preserve published marks, places, status and qualification tokens as recorded. Equal displayed times do not establish equal places; `Q` and `q` remain distinct. Missing wind, timing method, division, age basis or ruleset must stay unknown. Do not infer a historical athlete identity from a truncated name.

### Series standings and records lists

Use `series_standings` for printed season standings such as Grand Prix tables, and `association_records` for printed records lists. Copy every value as printed; the workflow does not compute places, points, totals or record status. Both collections accept only published values: `origin: computed` and `recomputed_points` are rejected. Optional printed fields may be blank strings; omit a field, or use `null`, when the source does not report it.

| `series_standings` field | Requirement |
| --- | --- |
| `key`, `source_locator` | Required, as for other records |
| `standings_kind` | Required: `individual` or `team` |
| `division_label_as_printed` | Required nonblank text |
| `name_as_printed` | Required nonblank text (athlete or team) |
| `series_label_as_printed`, `club_as_printed` | Optional text |
| `place_as_printed`, `total_as_printed` | Optional text, at most 64 characters |
| `race_points_as_printed` | Required JSON object; keys are race column labels as printed and values are printed strings. `{}` is allowed; a list is not |
| `origin` | Optional; must be `published` when present |

| `association_records` field | Requirement |
| --- | --- |
| `key`, `source_locator` | Required, as for other records |
| `division_as_printed`, `event_as_printed` | Required nonblank text |
| `record_status_as_listed` | Required nonblank text, such as `PA list as published` or `meet record` |
| `list_label_as_printed`, `holder_name_as_printed`, `club_as_printed`, `meet_as_printed` | Optional text |
| `mark_as_printed` | Optional text; omit it for a vacant record |
| `date_as_printed` | Optional text, at most 64 characters |
| `date_set` | Optional calendar date in `YYYY-MM-DD` form, only when the printed date is unambiguous |
| `is_relay` | Optional boolean |

As in other collections, additional fields are retained in the candidate and private review evidence but are not published. A listed status records what the source says; neither extraction review nor publication ratifies a record.

When a bundle contains several competition records, rounds, performances and teams must each identify their `competition_key`. A performance and its referenced round must share that competition, and team members must belong to the team's competition. A single competition can provide implicit display context without rewriting the original artifact. Event, division and round labels are displayed separately.

## Independently review and publish

The reviewing administrator must inspect the original source independently and compare all record identifiers and coverage, not merely accept the producer's JSON. In the candidate details, upload a verification report and the original source. The original producer cannot approve their own candidate.

```bash
wp --user=reviewer-admin pausatf-results archive inspect 123
wp --user=reviewer-admin pausatf-results archive review 123 --report=/archive/review.json --source=/archive/original.txt
wp --user=reviewer-admin pausatf-results archive publish 123
```

The report must bind to the exact `bundle_sha256` and `source_sha256`, specify `outcome` as `pass` or `fail`, describe the comparison in `evidence`, and supply `checked_records` and `findings` lists. Record references use `collection:key`, such as `performances:p1` or `series_standings:gp-men-1`. A passing report must list every standings and records row as well. Findings use `severity` (`warning` or `blocking`), a nonempty `message`, and an optional valid `record` reference.

A passing report must check every record, have no blocking findings and leave no `needs_review` coverage. A failing report moves the candidate to `rejected`. Reviews are immutable and limited to one per candidate. After rejection, produce and independently review a corrected new artifact rather than overwriting the rejected one.

Published values may remain faithful transcriptions with unknown rulesets. Computed rankings or scores require `origin: computed`, `ruleset_key`, `calculation_version` and nonempty recorded `inputs`. Any `recomputed_points` field requires the computed origin. A passing review must include the referenced ruleset in `verified_rulesets` with independent applicability evidence. The ruleset needs an explicit effective-date interval covering all dated competitions in the bundle. The current gate checks every competition, so split differently governed competitions into separate bundles when needed. A contemporary rulebook alone does not establish rules for an older meet. Extraction approval does not ratify a record claim.

Successful review changes `candidate` to `verified`; publication is a separate administrator action. Publication rechecks the unchanged bundle and its bound passing review. A repeated publication of an already published version is idempotent.

## Display published results

Insert the version-specific shortcode into a WordPress page:

```text
[pausatf_archive_release id="123"]
```

Individual tables lead with the recorded name and mark, followed by separate overall, sex-category and division place columns. Divisions are called age divisions only when the source supplies age context. These summaries use only unambiguous published places; multiple source scopes direct readers to the expandable place details. Calculated places remain separate, with readable category labels and origin information. Series standings appear in one table per series label, standings kind and printed division, in source order, with Place, Name, Club, one column per printed race label in first-seen order, and the printed total. Records lists appear in one table per list label and printed division with Event, Mark, Holder, Club, Date, Meet and listed status. A release without performances does not show an empty results table. Narrow screens wrap names and scroll the remaining context within the table. Keyboard users can focus the labelled table region and scroll horizontally.

Public REST endpoints expose only published versions:

```text
GET /wp-json/pausatf/v1/archive/releases/123
GET /wp-json/pausatf/v1/archive/releases/123/performances?page=1&per_page=50
GET /wp-json/pausatf/v1/archive/releases/123/rankings?page=1&per_page=50
GET /wp-json/pausatf/v1/archive/releases/123/series_standings?page=1&per_page=50
GET /wp-json/pausatf/v1/archive/releases/123/association_records?page=1&per_page=50
```

The release endpoint returns provenance and collection counts; releases staged before schema version 2 report zero standings and records rows. Collection endpoints return allowlisted public fields in their recorded order, without sorting equal marks or inferring missing context. The public response excludes raw bundle JSON, review evidence, producer identity and private source paths. The administrative endpoint `/wp-json/pausatf/v1/archive/review/123` requires `manage_options` and includes private evidence. Candidates, rejected and withdrawn releases are unavailable through public routes and the shortcode.

## Corrections, withdrawal and recovery

Create a new corrected bundle with `supersedes_sha256` referencing an existing bundle for the same logical competition. Stage, independently review and publish that new candidate. Supersession records lineage; it does not automatically withdraw the earlier version or change version-specific shortcodes. Update page references deliberately and withdraw the old version if it should no longer be public.

```bash
wp --user=reviewer-admin pausatf-results archive withdraw 123
```

Withdrawal removes a published version from the public API and shortcode while retaining its source identity, bundle, entity rows and review. Repeated withdrawal is idempotent. A withdrawn version cannot be directly republished; a new candidate must pass the workflow.

For an uncertain database outcome, inspect the candidate by its ID and artifact checksum before retrying. Do not interpret an unconfirmed operation as successful. Preserve the original failure evidence and compare stored state with the expected transition.

Application rollback means restoring the previously approved plugin artifact after a compatibility check. Retain the additive archive tables and original evidence; removing tables would destroy the review history. Disabling the plugin also removes its shortcode and API handlers, so evaluate affected pages before doing so. Database restoration is a separate recovery operation and can discard later changes across the WordPress database. No destructive cleanup, production installation, publication or deployment is performed by this guide.

## Upgrade from archive schema version 1

Archive schema version 2 adds the `pausatf_archive_standings` and `pausatf_archive_assoc_records` tables (with the WordPress table prefix) for the two new collections. The upgrade is additive: it does not alter existing tables, rows, bundles or reviews, and every existing release keeps its bundle checksum. After deploying the new plugin revision, run **Install archive tables** (or `wp --user=producer-admin pausatf-results archive install`) once. Installation applies the same safety checks as a fresh install, creates the two tables, verifies all table contracts, and only then records version 2. Until then, published releases continue to be served, but staging, review, publication and withdrawal are refused. A failed upgrade leaves the version 1 marker in place and can be retried after the reported problem is investigated.

Rolling back to the previous plugin revision leaves the two new tables in place and unused. The earlier revision does not accept version 2, so it continues to serve published releases but refuses archive writes until the newer plugin is restored. Do not lower the stored schema version by hand: candidates containing standings or records are rejected by the earlier revision, and a published release containing them would be served by the earlier revision without those collections. Do not drop the new tables during rollback; they hold reviewed evidence.
