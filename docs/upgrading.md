# Upgrading and operator notes

This page covers the changes merged since version 2.4.0 and what an operator must do, check or decide. Nothing in this upgrade deletes data. The list of changes by pull request is in [CHANGELOG.md](../CHANGELOG.md).

## What runs on upgrade

Schema changes run on `admin_init`, so they happen the first time any logged-in user loads a wp-admin page after the files change (including `admin-ajax.php` and `admin-post.php` requests). They are additive: `dbDelta()` adds columns and tables, and one obsolete index is dropped. No row is deleted, rewritten or re-scored.

| Revision option | Value after upgrade | What it adds |
| --- | --- | --- |
| `pausatf_results_schema` | `1` | `pausatf_results.sex_listed varchar(20)`. Set by `Plugin::maybe_upgrade()`. |
| `pausatf_results_gp_schema` | `2` | Series columns `ruleset varchar(32)` and `series_part varchar(10)`. Event columns `team_multiplier`, `is_championship` and `distance_m`. Standings columns `athlete_key`, `entry_type`, `standing_type` and `verified`, and `gender` now allows `X`. Drops the unique index `series_athlete` on the standings table (the index only; rows stay). Set by `GrandPrix::maybe_upgrade()`, and only while the Grand Prix feature is on. |
| `pausatf_results_version` | `2.4.0` | Installed version, written by the same upgrade. |

`Plugin::maybe_upgrade()` also runs when the version option is older than the plugin version, but the plugin header still reads 2.4.0, so a version comparison alone would not trigger the new results column. The schema option does. The upgrade is safe to run twice.

To confirm it ran:

```sh
wp option get pausatf_results_schema        # expect 1
wp option get pausatf_results_gp_schema     # expect 2 when Grand Prix is enabled
wp db query "SHOW COLUMNS FROM $(wp db prefix)pausatf_results LIKE 'sex_listed'"
```

The upgrade does not run from WP-CLI alone, because WP-CLI does not fire `admin_init`. Either load a wp-admin page, or deactivate and reactivate the plugin: activation runs the same installers and sets the schema options. Reactivation also reschedules the sync event and flushes rewrite rules. A feature that owns tables and is enabled later (`records_database`, `ranking_system`, `webhooks`) creates them on activation or through **Settings > Tools > Repair Database Tables**. That tool recreates `pausatf_results` and `pausatf_imports` from an older column list, and `dbDelta()` does not drop columns, so it does not remove `sex_listed` or the other newer columns.

## Changes that alter what you see

### Event dates

RM now takes an event's competition date from `_pausatf_event_start` (PAUSATF Site's wall-clock start in the event timezone) and falls back to the legacy `_pausatf_event_date`. Before, RM read only the legacy key, so events created in PAUSATF Site had no date in Grand Prix series, rankings, club and athlete histories, exports, certificates, webhooks, REST, GraphQL or JSON-LD.

- No action is needed. Events with either key work. Events imported by RM carry only the legacy key, and RM's importers still write only that key, so they keep working unchanged.
- A start that carries an offset is converted into the event timezone; a plain local start is used as written and is never shifted to the site timezone or UTC.
- Import de-duplication matches an existing event on either key.
- If an event has neither key its date is empty. Check events that show no date in lists.

### Sex as listed

Earlier releases stored only the first letter of the listed sex and dropped other values. Now `sex_listed` keeps the printed value and `sex` holds the scoring category: M, F, or null. Rows imported earlier keep their stored `sex`, and their `sex_listed` is null; the printed value cannot be recovered without re-importing from the source. A result whose sex is not M or F is shown and not scored. RM's age-division, event-standard and Grand Prix code no longer treat it as male. Every importer now writes both columns (see [classification-and-records.md](classification-and-records.md#sex-as-listed)). Before this, the legacy HTML importer, Hy-Tek, RunSignUp, Athlinks and the timing system importers stored no sex, and the CSV importer wrote the printed value unmapped into `sex`. Re-import those events to fill `sex` and `sex_listed`; results that stay null are not scored.

### Age divisions and classifications

Masters now start at 35 for track and field and race walking and at 40 for long distance running; Open needs 14 or 16 by discipline; U20 is a date test; Rule 320.2 groups M/W25 and M/W30 exist. Road rankings and record checks pass their discipline. Stored division codes are unchanged. Rankings built before the upgrade keep their old division codes until you regenerate them (**Settings > Tools > Regenerate All Rankings**, which rebuilds the current year).

### Placement time

ChronoTrack and Athlinks imports used chip time whenever it existed. They now follow the event's stated basis and default to gun time, and the ChronoTrack, Athlinks, MYLAPS, RunSignUp and UltraSignUp importers round up to the next whole second, where they used to truncate or round to nearest. Results already imported are not changed. Re-import an event to apply the new rule. To set a basis for an event, set its `_pausatf_placement_time` meta to `gun`, `net` or `combination`, or supply it with the `pausatf_results_placement_time` filter.

### Age grading

Earlier rankings were computed from placeholder factors. They carry no edition and are hidden. Age-graded rankings stay off until an administrator imports a factor table with WP-CLI and enables the feature. See [age-grading.md](age-grading.md).

### Road record course limits

`USATFCompetitionRules::road_course_meets_record_limits()` applies Rule 265.4 (drop at most 1 m per km, separation at most 50% of the distance). It is a helper. Record submission does not call it, so no stored record changes.

### Grand Prix

Series with an empty `ruleset` score as before. Setting `ruleset` to `pa_road_2026`, `pa_xc_2026` or `pa_mut_2026` switches that series to the 2026 rules and the next recompute replaces the series' standing rows, including their `entry_type` and `standing_type`. Back up `pausatf_grand_prix_standings` first if you need the old rows. A `ruleset` column that is empty on every existing series is the expected state after the upgrade. See [grand-prix.md](grand-prix.md).

Standings are recomputed only when `calculate_standings()` runs. Nothing in RM triggers it after an import.

## Sanctions Manager retired

The manager no longer loads. There is no admin page, no AJAX action, no REST route, no form, no fee table, no notification email and no reminder job. The three old shortcodes print a notice that links to PA's sanctions page. Change the link with the `pausatf_results_sanctions_url` filter, or edit the pages to remove the shortcodes.

Nothing was deleted: the four `pausatf_sanction*` tables, the notification option and the role capabilities remain. The feature was off by default, so many sites never created the tables. To export the records, or to remove them later once the association agrees, follow [sanctions-retirement.md](sanctions-retirement.md). The daily `pausatf_sanction_reminders` job is cleared on the next page load and on deactivation. The Race Director Portal's "USATF Sanctioned?" field is unrelated and unchanged.

## What a site must wire up

RM holds no membership, club or birth-date data. For Grand Prix standings to exclude non-members, score only PA clubs, and carry runners across decade boundaries correctly, the site supplies that data with filters. Register them from a plugin that has the data (normally PAUSATF Site or a membership integration), not from RM.

| Filter | Supply | If you do not |
| --- | --- | --- |
| `pausatf_results_gp_member_status` | `true` for a current PA member, `false` for a non-member, `null` when unknown | Nobody is removed as a non-member; every row is unverified |
| `pausatf_results_gp_is_pa_club` | `true` or `false` for the club name | Any club name scores as a team; team rows are unverified |
| `pausatf_results_gp_birth_date` | A `Y-m-d` date for the athlete key | Decade carry-over uses the earlier-race rule |
| `pausatf_results_gp_team_division` | A division code, for road drop-downs | Road teams use the runner's own age division |
| `pausatf_results_xc_team_division` | A division code, for XC drop-downs | XC teams use the runner's own age division |

An illustration of the shape; the lookup function is yours to write:

```php
add_filter( 'pausatf_results_gp_member_status', function ( $status, array $row ) {
    $member = my_membership_lookup( $row['athlete_name'], $row['athlete_id'] ?? null ); // true, false or null
    return $member ?? $status;
}, 10, 2 );
```

Other data RM expects from the site or its imports:

- `athlete_age` on each result row, as printed. Without it, the athlete's listed division is the only evidence of age, and the row stays unverified.
- Club names spelled consistently. RM compares clubs by a lowercase, space-normalized name and ignores `unattached`, `unatt`, `ua`, `none`, `n/a` and `-`.
- PAUSATF Site's Archive, for verified athlete identity. See [athlete-identity.md](athlete-identity.md).
- Optional: `_pausatf_placement_time` on events, and a callback for `pausatf_results_placement_time_combination` if events use a stated combination.
- A call to `GrandPrix::calculate_standings()` or the `pausatf_result_imported` action after imports.

## Archive deferral

When PAUSATF Site's Archive module is active, RM's own archive does not load. The menu, routes, shortcode and CLI described in [archive-context.md](archive-context.md) are absent. The `pausatf_archive_*` tables and their data stay in the database. If you used the bundled archive, keep it until production runs PAUSATF Site's archive, and check pages that contain `[pausatf_archive_release]` before enabling the site's module.

## Uninstalling

Deleting the plugin removes its options, including every stored API key and secret, and the per-user Strava and Garmin tokens. It leaves every table, the schema options (`pausatf_results_schema`, `pausatf_results_gp_schema`, `pausatf_results_version`), the imported age-grading table (`pausatf_results_age_grading_table`) and the roles' capabilities. Drop the tables by hand for a full removal.

Rolling back to an earlier plugin version leaves the new columns and tables in place and does not re-create the dropped Grand Prix index. This has not been tested against the earlier code.
