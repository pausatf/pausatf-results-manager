# Modules, admin screens and importers

Results Manager (RM) is a set of features behind toggles. Core results import and display, athletes, and the sanctions notice shortcodes always load. Everything else loads only while its feature is on.

## Features

Toggle them under **PAUSATF Results > Settings > Features**. The choice is stored in the option `pausatf_features`. A feature that depends on another cannot work without it. Changing a toggle can need a page reload, and a newly enabled feature that owns tables creates them on the next `admin_init` or through **Settings > Tools > Repair Database Tables**.

| Feature ID | Default | Needs | What it provides |
| --- | --- | --- | --- |
| `core_results`, `core_athletes` | On, fixed | | Results import and display, athlete profiles. |
| `usatf_rules_engine` | On | | The Rules classes. See [classification-and-records.md](classification-and-records.md). |
| `records_database` | On | rules engine | Association records with a pending-review workflow. |
| `ranking_system` | On | rules engine | Seasonal, all-time and age-graded rankings. |
| `age_graded_rankings` | Off | ranking system | Age-graded rankings. Needs an imported factor table. See [age-grading.md](age-grading.md). |
| `athlete_dashboard` | On | | `[pausatf_athlete_dashboard]`. |
| `athlete_claim` | On | | Users claim athlete profiles; an administrator reviews the claim. |
| `certificates` | On | | Finisher certificates and share cards. Needs the PHP `gd` extension. |
| `grand_prix` | On | | Grand Prix series and standings. See [grand-prix.md](grand-prix.md). |
| `performance_tracker` | On | | Personal records and performance trends. |
| `club_manager` | On | | Club profiles, rosters and cross country team standings per race. Team standings read the `sex` column, so results with no stored sex are not scored. |
| `race_director_portal` | On | | Race directors create events and upload results (CSV or Hy-Tek files) from `[pausatf_rd_portal]`. |
| `csv_importer` | On | | CSV import with flexible column mapping. |
| `data_exporter` | On | | Results export as CSV or PDF. |
| `hytek_importer` | On | | Hy-Tek Meet Manager files. |
| `runsignup_integration` | On | | RunSignUp race and result import. |
| `athlinks_integration` | Off | | Athlinks athlete history. |
| `usatf_verification` | Off | | USATF membership number lookup. |
| `timing_systems` | On | | ChronoTrack, MYLAPS, Webscorer and RaceTab clients. |
| `strava_sync` | Off | | Strava and Garmin activity links. |
| `ultrasignup_import` | On | | UltraSignUp race and result import. |
| `rest_api` | On | | The public `pausatf/v1` results routes. |
| `graphql_api` | Off | | The `/pausatf-graphql` endpoint. |
| `webhooks` | Off | | Signed outbound notifications with retry. |
| `rdf_support` | Off | | RDF export and the SPARQL endpoint. |

The integrations and the CSV and Hy-Tek importers are code classes. Apart from the Race Director Portal, which calls the CSV and Hy-Tek importers, and Strava's OAuth callback, they have no admin screen, command or route; a site calls them from its own code. Credentials for RunSignUp, Athlinks, USATF and Strava are entered on **Settings > Integrations**. The timing system and Garmin keys are options with no form field.

## Admin screens

All screens need `manage_options`, except the Race Director Portal.

| Screen | Where | What it does |
| --- | --- | --- |
| Dashboard | **PAUSATF Results** | Event, athlete and result counts, quick links and recent imports. |
| Import | **PAUSATF Results > Import** | Import one page by URL, analyze a URL's format, queue a batch import for a year from 1994 to 2025, and see the next scheduled sync. The "Upload HTML File" form has no handler behind it in this release. |
| Settings | **PAUSATF Results > Settings** | Four tabs, below. |
| RDF/SPARQL | **PAUSATF Results > RDF/SPARQL** | Endpoint list, query form, export links and documentation. RDF feature only. |
| Webhooks | **PAUSATF Results > Webhooks** | List registered webhooks, add one, and see the selectable events. Deliveries are logged in `pausatf_webhook_logs`. Webhooks feature only. |
| RD Portal | **PAUSATF Results > RD Portal** | Admin view of the portal. Needs `pausatf_race_director`. |
| Archive review | **PAUSATF Results > Archive review** | The bundled archive workflow. Not loaded while PAUSATF Site's Archive is active. |
| Profile Claims | **PAUSATF Results > Profile Claims** | Claim posts. Athlete Self-Claim only. |

Settings tabs:

- **Features.** The toggles, with Enable All, Disable Optional and Reset to Defaults buttons.
- **General.** Automatic sync (on or off, and `hourly`, `twicedaily`, `daily` or `weekly`), automatic athlete creation, the minimum event count for a profile, and the read-only legacy source URL (`https://www.pausatf.org/data/`, the constant `PAUSATF_LEGACY_SOURCE_URL`).
- **Integrations.** Keys for RunSignUp, Athlinks, USATF and Strava.
- **Tools.** Bulk operations and maintenance:
  - Create Missing Athlete Profiles for names that meet the minimum event count.
  - Re-parse All Events marks completed import-log rows as pending so the sync can pick them up. It does not download anything itself.
  - Scan All Results for Potential Records, which queues pending records for review.
  - Regenerate All Rankings rebuilds every event, sex and division for the current year.
  - Repair Database Tables re-runs the table installers for enabled features.
  - Clear All Caches deletes the plugin's transients.
  - Delete All Data, described below.

**Delete All Data** truncates `pausatf_results` and `pausatf_imports`, permanently deletes every `pausatf_event` and `pausatf_athlete` post (including events that PAUSATF Site owns), and truncates any of these tables that exist: `pausatf_records`, `pausatf_rankings`, `pausatf_grand_prix`, `pausatf_grand_prix_events`, `pausatf_grand_prix_points`, `pausatf_webhooks` and `pausatf_webhook_deliveries`. Three of those names (`pausatf_grand_prix`, `pausatf_grand_prix_points` and `pausatf_webhook_deliveries`) are not tables RM creates. In practice the action empties the Grand Prix events table but not the series or standings tables, and it leaves the webhook delivery log (`pausatf_webhook_logs`) and `pausatf_pending_records` alone. It needs only `manage_options` and the page nonce, and it cannot be undone.

## How results get in

| Path | Entry point | Stores through | Notes |
| --- | --- | --- | --- |
| Legacy HTML by URL | Import screen, `POST /pausatf/v1/import`, the sync | `ResultsImporter` | Detects table, fixed-width and Word-generated HTML. Replaces the event's existing results. |
| Scheduled sync and batch | `pausatf_results_sync`, `pausatf_batch_import` | `ResultsImporter` | Reads the file listing at `PAUSATF_LEGACY_SOURCE_URL`. |
| Canonical event JSON | `POST /pausatf/v1/events/import`, the calendar command | `CanonicalEventService`, then `ResultsRepository` | Creates or updates the event by `event_uid`. Links athletes by source identifier. Sets PAUSATF Site's start, timezone and state keys when that module is loaded. |
| CSV, Hy-Tek | Race Director Portal; code | `CSVImporter`, `HyTekImporter` | |
| RunSignUp, Athlinks, timing systems | Code | Each class | Placement time follows the event's stated basis. |
| UltraSignUp | Code | `UltraSignupImport` | |

All of them write the legacy `_pausatf_event_date`. Which of them keep sex as listed is in [classification-and-records.md](classification-and-records.md#sex-as-listed). The scheduled sync's and batch import's directory listing requests, and the Import screen's Analyze request, are made with TLS certificate verification turned off. `ResultsImporter::import_from_url()`, which fetches the result pages, uses WordPress's default verification.

## Parsers

`ParserDetector` tries the registered parsers in priority order, lowest number first, and uses the first that can parse the page: `ParserWord` (10, Word-generated HTML), `ParserTable` (20, HTML tables, 2008 and later) and `ParserPre` (30, fixed-width text, 1996 to 2007). To add one, implement `ParserInterface` (`can_parse()`, `parse()`, `get_priority()`, `get_id()`) and register it in `ParserDetector::register_default_parsers()`.
