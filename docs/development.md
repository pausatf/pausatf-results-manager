# Development and CI

## Requirements

- PHP 8.4 or newer and Composer. The code uses PHP 8.4 syntax (property hooks in `includes/models/class-athlete-result.php`).
- Node 22.22.2 or newer and npm 10 or newer, for the JavaScript and CSS assets only.
- WordPress 6.0 or newer to run the plugin. CI runs WordPress 7.1.x.

## Commands

```sh
composer install
composer hooks:install      # sets core.hooksPath to .githooks
vendor/bin/phpunit          # unit tests
composer analyze            # PHPStan, level 8, with the WordPress extension
composer lint               # PHPCS, WordPress standard
composer test:e2e           # Codeception, needs the e2e harness (see tests/e2e/README.md)
```

Node tooling is selected with mise. Use the lockfile and do not force peer resolution:

```sh
mise exec node@22.22.2 -- npm ci
mise exec node@22.22.2 -- npm run build
mise exec node@22.22.2 -- npm run lint
mise exec node@22.22.2 -- npm run test:unit
```

`npm run test:unit` passes with no tests, so a pass alone says nothing about coverage. The JavaScript toolchain pins `@wordpress/scripts` 37, React 18.3 and ESLint 9.39. Dependabot ignores React 19 and ESLint 10 until the affected packages support them, and keeps minor and patch updates.

## Tests

`vendor/bin/phpunit` runs every `*Test.php` file under `tests/`, excluding `tests/e2e`. The suite uses WordPress function stubs from `tests/bootstrap.php` and an in-memory `$wpdb` double for the Grand Prix tests. The archive tests that need a real MySQL server are skipped unless `PAUSATF_ARCHIVE_TEST_MYSQL_HOST` and the related `PAUSATF_ARCHIVE_TEST_MYSQL_*` variables point at one, so a local run reports them as skipped. CI sets those variables and fails on any skip.

At the time of writing, a local run without MySQL reports 389 tests and 36 skipped. Counts change with every merge; read the result, not this number.

The suites that cover the rules in these docs: `AgeDivisionsTest`, `ListedSexTest`, `EventDateTest`, `PlacementTimeTest`, `RoadRecordCourseTest`, `AgeGradingTest`, `AgeGradedRankingsTest`, `AthleteIdentityTest`, `GrandPrixRulesTest`, `GrandPrixScoringTest`, `GrandPrixStandingsTest`, `XCTeamScoringTest`, `SanctionsRetirementTest` and `ArchiveOwnershipTest`.

## Pre-push hook

`.githooks/pre-push` checks the files changed since the upstream branch for merge-conflict markers, runs `php -l` on changed PHP, runs `actionlint` on changed workflows when it is installed, and runs the parser tests when parser, importer, repository, Composer or PHPUnit files change. Run `composer install` first, since the hook calls `composer test`. Do not bypass it.

## Continuous integration

| Workflow | Runs on | What it does |
| --- | --- | --- |
| `php-syntax.yml` | push to main, pull requests | `php -l` on every tracked PHP file, then `vendor/bin/phpunit`. |
| `archive-context.yml` | push to main, pull requests | PHPUnit with `--fail-on-skipped` against MySQL 8.4, then native WordPress 7.1.4 HTTP checks of the archive. |
| `e2e-tests.yml` | push to main, pull requests, manual | Codeception API and acceptance suites on WordPress 7.1.1 and MySQL 8.4. A Docker job runs on manual dispatch only. |
| `codeql.yml` | push to main, pull requests, Mondays 06:00 UTC, manual | CodeQL for `actions` and `javascript`, using `.github/codeql/codeql-config.yml`, which excludes PHP and `tests/e2e/vendor`. CodeQL does not analyze the PHP code. |
| `sbom.yml` | changes to Composer or npm manifests, pull requests, releases | CycloneDX SBOMs and dependency audits for PHP and npm. |

Workflow hardening: actions are pinned to commit SHAs, tokens have the least permission each job needs (`contents: read` by default), and checkouts use `persist-credentials: false`.

Dependabot (`.github/dependabot.yml`) checks GitHub Actions, Composer (root and `tests/e2e`), npm, Docker Compose and the e2e image weekly on Mondays, with a seven-day cooldown. The three `github/codeql-action` steps (`init`, `autobuild`, `analyze`) must run the same version, so Dependabot groups them into one pull request.

## Release builds

`.gitattributes` marks development files `export-ignore`, so `git archive` and Composer dist installs ship only what WordPress loads. `tools/` stays because it holds WP-CLI commands that run on the server. `docs/` and `tests/` are not shipped. Plugin metadata is in the header of `pausatf-results.php` and in `readme.txt`; update both, and `CHANGELOG.md`, with a release. `package.json` carries its own version, `3.0.0`, which does not track the plugin version.

## Layout

```
pausatf-results.php        Plugin bootstrap, post types, taxonomies, schema upgrade
includes/                  Classes (PSR-4 namespace PAUSATF\Results\)
  parsers/                 HTML parsers and the detector
  rules/                   USATF rules engine
  storage/                 ResultsRepository
  archive/                 Bundled archive workflow (defers to PAUSATF Site)
  integrations/            RunSignUp, Athlinks, timing systems, UltraSignUp, Hy-Tek, USATF, Strava
admin/                     Settings and import screens
public/                    Shortcodes, REST routes, front-end display, JSON-LD
cron/                      Sync scheduler
tools/                     WP-CLI commands loaded with --require
schemas/                   Canonical event JSON schema
tests/                     PHPUnit, integration and e2e suites
docs/                      This documentation
```

The autoloader in `pausatf-results.php` turns a class name under `PAUSATF\Results\` into `includes/class-<name>.php`, lower-cased, with underscores and namespace separators becoming hyphens. CamelCase names such as `GrandPrix` do not become `class-grand-prix.php`, so `Plugin::load_components()` loads classes with explicit `require_once`, and each test requires the files it exercises. A new class needs a `require_once` in the right feature block.
