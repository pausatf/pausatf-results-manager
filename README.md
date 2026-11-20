# PAUSATF Results Manager

A WordPress plugin that imports Pacific Association USA Track & Field (PAUSATF) competition results from legacy HTML pages and other sources, stores them with athlete and event records, and applies USATF and PA scoring rules to them. It exists because PA's results span 1994 to 2025 in several incompatible page formats, and the association's rules (age classifications, placement time, Grand Prix scoring) need to be applied the same way to all of them.

It is built for the PAUSATF site and runs next to PAUSATF Site, which owns events, the results archive and athlete identity. It also runs on its own.

## What it does

- **Imports results** from HTML tables (2008 on), fixed-width text (1996 to 2007) and Word-generated HTML, by URL, by year, on a schedule, or from canonical event JSON. CSV, Hy-Tek, RunSignUp, Athlinks, UltraSignUp and timing-system importers are available to site code.
- **Displays results** with shortcodes, a public REST API, and optional GraphQL, RDF and SPARQL endpoints.
- **Applies USATF rules** from the 2026 Competition Rules: Rule 141 classifications, Rule 320 masters divisions, sex kept as listed, Rule 165 placement time and Rule 265.4 road record course limits. See [classification-and-records.md](docs/classification-and-records.md).
- **Computes Grand Prix standings** for the 2026 PA road, cross country and MUT rules, including team scoring. See [grand-prix.md](docs/grand-prix.md).
- **Tracks athletes, records and rankings**, with age-graded rankings that stay off until an age-grading table is imported. See [age-grading.md](docs/age-grading.md).

## Status and limits

- Grand Prix standings are computed from the PA rule texts and tested on constructed data. They have not been reconciled with standings PA has published, and every row is unverified until the site supplies membership and club data. Treat them as unofficial. See [grand-prix.md](docs/grand-prix.md).
- The plugin ships no age-grading factors. Age-graded rankings need an imported table.
- RM does not check membership, residency or eligibility for a sex category. It records what the results list.
- Several admin features have no screen and are driven from code or WP-CLI: Grand Prix series, and most integrations. See [modules.md](docs/modules.md).

## Requirements

- WordPress 6.0 or newer (CI runs 7.1.x)
- PHP 8.4 or newer
- MySQL 8.0 or newer (rankings use window functions; CI runs 8.4)

## Install

1. Copy the `pausatf-results-manager` folder to `/wp-content/plugins/`. The Composer package `pausatf/results-manager` has type `wordpress-plugin`.
2. Activate it under **Plugins**. Activation creates the core tables and schedules the sync event.
3. Open **PAUSATF Results** in the admin menu. Choose features under **Settings > Features**.

Upgrading from an earlier release? Read [upgrading.md](docs/upgrading.md). Nothing in the upgrade deletes data.

## Use

Import a results page from **PAUSATF Results > Import**: enter a URL from `https://www.pausatf.org/data/`, choose **Analyze** to preview the detected format, then **Import Results**. To import a whole year, use **Batch Import by Year**. Canonical event JSON can be posted to `/wp-json/pausatf/v1/events/import` by an administrator.

Shortcodes:

```text
[pausatf_results event_id="123"]
[pausatf_results year="2024" division="Open"]
[pausatf_athlete name="John Smith"]
[pausatf_leaderboard division="Masters 40+" year="2024"]
[pausatf_search]
[pausatf_grand_prix series="road-short" year="2026" type="division"]
```

Public REST routes (namespace `pausatf/v1`):

```text
GET /wp-json/pausatf/v1/events/{id}/results
GET /wp-json/pausatf/v1/athletes/search?q=smith
GET /wp-json/pausatf/v1/athletes/{name}/results
GET /wp-json/pausatf/v1/leaderboard?division=Open&year=2024
GET /wp-json/pausatf/v1/divisions
GET /wp-json/pausatf/v1/seasons
```

Import routes (`POST /import`, `POST /events/import`) require an administrator. The full list of shortcodes, routes, hooks, CLI commands, cron jobs and tables is in [reference.md](docs/reference.md).

## Changes that affect operators

- **Sanctions Manager retired.** USATF takes sanction applications online (2026 USATF Governance Handbook, Regulation 23-C), so the manager no longer loads. Old sanction data is kept. See [sanctions-retirement.md](docs/sanctions-retirement.md).
- **Archive deferred.** When PAUSATF Site's Archive module is active, RM's own archive (menu, `pausatf/v1/archive/*` routes, `[pausatf_archive_release]` and the `archive` CLI) does not load. See [archive-context.md](docs/archive-context.md).
- **Event dates** come from `_pausatf_event_start` first, then `_pausatf_event_date`.
- **Sex** is stored as listed. A value other than M or F is shown but not scored.

Details and the steps to take are in [upgrading.md](docs/upgrading.md).

## Documentation

| Page | Covers |
| --- | --- |
| [docs/upgrading.md](docs/upgrading.md) | Schema upgrade, retired and changed behavior, data a site must supply |
| [docs/reference.md](docs/reference.md) | Post types, tables, options, REST, shortcodes, CLI, cron, hooks |
| [docs/modules.md](docs/modules.md) | Features, admin screens, importers, parsers |
| [docs/classification-and-records.md](docs/classification-and-records.md) | Classifications, divisions, sex, placement time, records, with rule cites |
| [docs/grand-prix.md](docs/grand-prix.md) | Road, cross country and MUT standings, hooks, open questions |
| [docs/age-grading.md](docs/age-grading.md) | Factor table format, import, why rankings stay off |
| [docs/athlete-identity.md](docs/athlete-identity.md) | How a printed name becomes an identity key |
| [docs/sanctions-retirement.md](docs/sanctions-retirement.md) | Exporting or removing old sanction data |
| [docs/archive-context.md](docs/archive-context.md) | The bundled archive workflow |
| [docs/development.md](docs/development.md) | Tests, CI, hooks, layout |
| [tests/e2e/README.md](tests/e2e/README.md) | The end-to-end harness |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

## Development

```sh
composer install
vendor/bin/phpunit
```

Node tooling for the JavaScript and CSS assets needs Node 22.22.2 or newer; install with `npm ci` and do not force peer resolution. Commands, CI and the pre-push hook are in [development.md](docs/development.md). Repository CI does not establish a production deployment.

New parsers implement `ParserInterface` (`can_parse()`, `parse()`, `get_priority()`, `get_id()`) and are registered in `ParserDetector::register_default_parsers()`.

## Security

Report vulnerabilities privately through GitHub's private vulnerability reporting; see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
