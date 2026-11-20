# Changelog

This file follows the [Keep a Changelog](https://keepachangelog.com/) layout. Pull request numbers refer to the `pausatf/pausatf-results-manager` repository. Upgrade steps for the unreleased changes are in [docs/upgrading.md](docs/upgrading.md). History before 2.4.0 is in the git log and the version tags.

## Unreleased

Changes on `main` since 2.4.0, in merge order.

### Added

- The Import screen's **Upload HTML File** form now imports the page. It had no handler. It accepts one `.html` or `.htm` file up to 10 MB, checks the nonce and `manage_options`, and parses through `ResultsImporter` like a URL import.
- Grand Prix standings for the PA 2026 road, cross country and MUT rules. Series gain `ruleset` and `series_part`, series events gain `team_multiplier`, `is_championship` and `distance_m`, and standings gain `athlete_key`, `entry_type`, `standing_type` and `verified`. Series without a ruleset keep the earlier scoring. See [docs/grand-prix.md](docs/grand-prix.md). (#118)
- Athlete identity through PAUSATF Site's Archive, with a fallback to RM's athlete post and then a normalized name. See [docs/athlete-identity.md](docs/athlete-identity.md). (#117)
- Cross country team scoring after the PA Cross Country Rules updated 3/1/26: divisions, displacers, B teams, short teams and tie-breaks. (#116)
- Age grading from an imported factor table, with `wp pausatf-results age-grading import` and `status`. See [docs/age-grading.md](docs/age-grading.md). (#115)
- Placement time by the event's stated basis (gun, net or combination), with official times rounded up to the next whole second (Rules 165.11(c) and 165.16). (#113)
- Rule 265.4 road record course limits as `USATFCompetitionRules::road_course_meets_record_limits()`. (#112)
- A `sex_listed` column that keeps sex as the results list it. (#111)
- Rule 141 classifications per discipline, and Rule 320.2 masters-meet groups M/W25 and M/W30. (#110)
- `EventDate`, the single reader of an event's competition date. (#109)
- `SECURITY.md`. (#107)
- `.gitattributes` export rules that keep development files out of dist archives. (#102)

### Changed

- Event dates are read from `_pausatf_event_start` first, then `_pausatf_event_date`. (#109)
- Age divisions depend on the discipline: masters start at 35 for track and field and race walking and 40 for long distance running; Open is 14 or 16 by discipline; U20 is a date test. (#110)
- Results store the scoring category in `sex` (M, F or null). A sex other than M or F is shown and not scored, and is no longer treated as male. (#111)
- Age-graded rankings are off by default and show only rows from the loaded table edition. Earlier rows computed from placeholder factors are hidden. (#115)
- ChronoTrack, Athlinks, MYLAPS, RunSignUp and UltraSignUp importers round times up to the next whole second and place by the event's basis, gun time by default. (#113)
- Road record checks no longer say point-to-point courses are never eligible. (#112)
- When PAUSATF Site's Archive module is active, the bundled archive (admin menu, `pausatf/v1/archive/*` routes, `[pausatf_archive_release]` and the `archive` CLI) does not load. The `pausatf_results_defer_archive_to_site` filter forces either behavior. (#104)
- Documentation rewritten to match the code: README, `readme.txt`, and the pages under `docs/`.

### Removed

- The Sanctions Manager: its admin page, AJAX actions, REST routes, forms, fee table, notification emails, reminder cron job and Features entry. Its tables, option and role capabilities are kept. The three sanction shortcodes print a notice linking to PA's sanctions page. See [docs/sanctions-retirement.md](docs/sanctions-retirement.md). (#114)

### Fixed

- Placeholder age factors and an age-graded percentage that ignored the factor. (#115)
- XC club team standings that depended on an unspecified MySQL variable ordering. (#116)

### CI and dependencies

- Grouped `github/codeql-action` updates, with `init`, `autobuild` and `analyze` on one version. (#103)
- MySQL 8.4 in end-to-end tests, no package cache in the SBOM workflow, exact pin comments, and the `develop` branch trigger removed. (#107)
- Dependency updates: PHPUnit 13.4.6, `softprops/action-gh-release` 3.0.4, and the npm group. (#97, #99, #100)

## 2.4.0

- Defers the `pausatf_event` post type to PAUSATF Site when its Events module is active, and keeps canonical event imports publishable. (#101)

## 2.2.0

- Security hardening for certificate and share-card generation, DOM-safe athlete search, TLS verification enabled on imports.
