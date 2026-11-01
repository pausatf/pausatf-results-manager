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

Bundles use `schema_version: 1`, a logical `competition_key`, source metadata, all eleven entity collections, and source coverage entries. Every record needs a unique key within its collection and a source locator. Every record must occur once in coverage; excluded source portions need reasons. The reviewer must still compare completeness against the actual source: the workflow does not infer every row that a source ought to contain.

Current limits and restrictions:

| Input | Limit or behavior |
| --- | --- |
| Bundle JSON or review JSON | 8 MiB; valid UTF-8 JSON with maximum nesting depth 64 |
| Original source | 32 MiB; checksum and byte count must match the bundle |
| Entity records | 5,000 total across all eleven collections |
| Entity key | 1–96 ASCII letters, digits, underscores, periods, colons or hyphens; first character alphanumeric |
| Declared source format | `html`, `text`, `pdf`, `ole`, `zip`, or `elf` |
| Binary source | Actual signature must match; only ruleset, competition and result-release metadata are accepted |
| Public collection page | At most 100 records; page number at most 100,000 |

WordPress, PHP and the web server can impose smaller upload/request limits. Binary metadata staging does not execute, unpack, render or extract the uploaded file. Structured extraction needs a separately verified conversion and its own source evidence.

Preserve published marks, places, status and qualification tokens as recorded. Equal displayed times do not establish equal places; `Q` and `q` remain distinct. Missing wind, timing method, division, age basis or ruleset must stay unknown. Do not infer a historical athlete identity from a truncated name.

When a bundle contains several competition records, rounds, performances and teams must each identify their `competition_key`. A performance and its referenced round must share that competition, and team members must belong to the team's competition. A single competition can provide implicit display context without rewriting the original artifact. Event, division and round labels are displayed separately.

## Independently review and publish

The reviewing administrator must inspect the original source independently and compare all record identifiers and coverage, not merely accept the producer's JSON. In the candidate details, upload a verification report and the original source. The original producer cannot approve their own candidate.

```bash
wp --user=reviewer-admin pausatf-results archive inspect 123
wp --user=reviewer-admin pausatf-results archive review 123 --report=/archive/review.json --source=/archive/original.txt
wp --user=reviewer-admin pausatf-results archive publish 123
```

The report must bind to the exact `bundle_sha256` and `source_sha256`, specify `outcome` as `pass` or `fail`, describe the comparison in `evidence`, and supply `checked_records` and `findings` lists. Record references use `collection:key`, such as `performances:p1`. Findings use `severity` (`warning` or `blocking`), a nonempty `message`, and an optional valid `record` reference.

A passing report must check every record, have no blocking findings and leave no `needs_review` coverage. A failing report moves the candidate to `rejected`. Reviews are immutable and limited to one per candidate. After rejection, produce and independently review a corrected new artifact rather than overwriting the rejected one.

Published values may remain faithful transcriptions with unknown rulesets. Computed rankings or scores require `origin: computed`, `ruleset_key`, `calculation_version` and nonempty recorded `inputs`. Any `recomputed_points` field requires the computed origin. A passing review must include the referenced ruleset in `verified_rulesets` with independent applicability evidence. The ruleset needs an explicit effective-date interval covering all dated competitions in the bundle. The current gate checks every competition, so split differently governed competitions into separate bundles when needed. A contemporary rulebook alone does not establish rules for an older meet. Extraction approval does not ratify a record claim.

Successful review changes `candidate` to `verified`; publication is a separate administrator action. Publication rechecks the unchanged bundle and its bound passing review. A repeated publication of an already published version is idempotent.

## Display published results

Insert the version-specific shortcode into a WordPress page:

```text
[pausatf_archive_release id="123"]
```

Individual tables lead with the recorded name and mark; ranking tables lead with the name and place. Narrow screens wrap names and scroll the remaining context within the table. Keyboard users can focus the labelled table region and scroll horizontally.

Public REST endpoints expose only published versions:

```text
GET /wp-json/pausatf/v1/archive/releases/123
GET /wp-json/pausatf/v1/archive/releases/123/performances?page=1&per_page=50
GET /wp-json/pausatf/v1/archive/releases/123/rankings?page=1&per_page=50
```

The release endpoint returns provenance and collection counts. Collection endpoints return allowlisted public fields in their recorded order, without sorting equal marks or inferring missing context. The public response excludes raw bundle JSON, review evidence, producer identity and private source paths. The administrative endpoint `/wp-json/pausatf/v1/archive/review/123` requires `manage_options` and includes private evidence. Candidates, rejected and withdrawn releases are unavailable through public routes and the shortcode.

## Corrections, withdrawal and recovery

Create a new corrected bundle with `supersedes_sha256` referencing an existing bundle for the same logical competition. Stage, independently review and publish that new candidate. Supersession records lineage; it does not automatically withdraw the earlier version or change version-specific shortcodes. Update page references deliberately and withdraw the old version if it should no longer be public.

```bash
wp --user=reviewer-admin pausatf-results archive withdraw 123
```

Withdrawal removes a published version from the public API and shortcode while retaining its source identity, bundle, entity rows and review. Repeated withdrawal is idempotent. A withdrawn version cannot be directly republished; a new candidate must pass the workflow.

For an uncertain database outcome, inspect the candidate by its ID and artifact checksum before retrying. Do not interpret an unconfirmed operation as successful. Preserve the original failure evidence and compare stored state with the expected transition.

Application rollback means restoring the previously approved plugin artifact after a compatibility check. Retain the additive archive tables and original evidence; removing tables would destroy the review history. Disabling the plugin also removes its shortcode and API handlers, so evaluate affected pages before doing so. Database restoration is a separate recovery operation and can discard later changes across the WordPress database. No destructive cleanup, production installation, publication or deployment is performed by this guide.
