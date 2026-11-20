# Reference

Every interface Results Manager (RM) exposes, as of the main branch at version 2.4.0 plus the changes listed under Unreleased in [CHANGELOG.md](../CHANGELOG.md). Feature gates refer to the toggles in [modules.md](modules.md). A route or hook marked "feature" exists only while that feature is on.

## Content types

| Type | Registered by | Notes |
| --- | --- | --- |
| Post type `pausatf_event` | RM, or PAUSATF Site when its Events module is active | RM registers it (slug `results`) only if no other plugin has. When PAUSATF Site owns it, RM adds `custom-fields` support so the meta below reaches REST, and the site decides the permalink base. RM stores the owner in the option `pausatf_results_event_type_owner` and flushes rewrite rules once when it changes. |
| Post type `pausatf_athlete` | RM | Slug `athletes`; supports title, thumbnail and custom fields. |
| Post type `pausatf_club` | Club Manager feature | |
| Post type `pausatf_claim` | Athlete Self-Claim feature | Private, shown under the **PAUSATF Results** menu. |
| Taxonomies | RM | `pausatf_event_type`, `pausatf_season`, `pausatf_discipline` and `pausatf_series` on events; `pausatf_division` on events and athletes; `pausatf_region` on clubs. |

### Event meta

Registered for REST (`show_in_rest`, edit requires `edit_posts`): `_pausatf_event_uid`, `_pausatf_start_datetime`, `_pausatf_end_datetime`, `_pausatf_event_status`, `_pausatf_attendance_mode`, `_pausatf_venue_name`, `_pausatf_address_locality`, `_pausatf_address_region`, `_pausatf_postal_code`, `_pausatf_country`, `_pausatf_map_url`, `_pausatf_registration_url`, `_pausatf_registration_status`, `_pausatf_organizer_name`, `_pausatf_organizer_url`, `_pausatf_source_url`, `_pausatf_source_site`, `_pausatf_source_hash`, `_pausatf_import_confidence`, `_pausatf_review_status` and `_pausatf_last_synced_at`. The athlete type registers `_pausatf_athlete_uid` and `_pausatf_name_normalized`. The contract is described by `schemas/pausatf-event.schema.json`.

Other keys RM reads or writes:

| Key | Role |
| --- | --- |
| `_pausatf_event_start`, `_pausatf_event_timezone` | Written by PAUSATF Site. The competition date comes from the start key first. See [Event dates](#event-dates). |
| `_pausatf_event_date` | The legacy date. Every RM importer still writes it, and RM reads it when no start key exists. |
| `_pausatf_placement_time` | `gun`, `net` or `combination`. Set by a site or an editor, never by RM. See [classification-and-records.md](classification-and-records.md#placement-time-rule-165). |
| `_pausatf_event_location`, `_pausatf_imported_at`, `_pausatf_result_count` | Set by importers. |
| `_pausatf_claimed_by` | Athlete post meta: the user who claimed the profile. |
| `_pausatf_athlete_id` | User meta: the athlete post a user claimed. |

### Event dates

`EventDate::get($event_id)` returns a `Y-m-d` string, or an empty string. It prefers `_pausatf_event_start`, a wall-clock `Y-m-d H:i:s` in the event's own timezone (`_pausatf_event_timezone`), and uses its date part without shifting it. A start that carries an explicit offset (for example `2026-03-07T08:00:00Z`) is converted into the event timezone when that is a valid identifier. If no valid start exists, it returns `_pausatf_event_date` unchanged. SQL readers use `EventDate::sql_join()` and `EventDate::sql_expr()`, which join both keys and take the first 10 characters of the start meta, else the legacy value. `EventDate::order_query($args, 'ASC'|'DESC')` orders `get_posts()` and `WP_Query` by competition date through the `pausatf_event_date_order` query var.

## Database

Core tables are created on activation and upgraded on `admin_init` (see [upgrading.md](upgrading.md)). Names carry the site's table prefix.

| Table | Purpose |
| --- | --- |
| `pausatf_results` | One row per result. Includes `sex`, `sex_listed`, `athlete_age`, `place`, `division`, `time_seconds`, `club`, `raw_data` and others. |
| `pausatf_imports` | Import log (`pending`, `processing`, `completed`, `failed`). |
| `pausatf_records`, `pausatf_pending_records` | Association records and the submissions awaiting review. Records feature. |
| `pausatf_rankings` | Seasonal, all-time and age-graded rankings, with `age_grade_edition`. Ranking System feature. |
| `pausatf_grand_prix_series`, `pausatf_grand_prix_events`, `pausatf_grand_prix_standings` | Grand Prix. See [grand-prix.md](grand-prix.md). |
| `pausatf_webhooks`, `pausatf_webhook_logs` | Webhooks feature. |
| `pausatf_archive_*` | Bundled archive tables (schema version 2). Created only by an explicit install. See [archive-context.md](archive-context.md). |
| `pausatf_sanctions`, `pausatf_sanction_coi`, `pausatf_sanction_reports`, `pausatf_sanction_history` | Retired. RM no longer reads or writes them. See [sanctions-retirement.md](sanctions-retirement.md). |

### Options

| Option | Purpose |
| --- | --- |
| `pausatf_features` | Feature toggles. |
| `pausatf_sync_enabled`, `pausatf_sync_frequency` | Scheduled sync switch and interval (`hourly`, `twicedaily`, `daily`, `weekly`). |
| `pausatf_auto_create_athletes`, `pausatf_min_events_for_athlete` | Athlete profile creation settings (default minimum 3 events). |
| `pausatf_results_version` | Installed plugin version, used to trigger the schema upgrade. |
| `pausatf_results_schema` | Results schema revision (1 adds `sex_listed`). |
| `pausatf_results_gp_schema` | Grand Prix schema revision (2 adds `ruleset` and drops the name key). |
| `pausatf_results_age_grading_table` | The imported age-grading table. Not autoloaded. |
| `pausatf_results_event_type_owner` | `pausatf-site` or `pausatf-results`. |
| Integration credentials | `pausatf_runsignup_api_key` and `_api_secret`, `pausatf_athlinks_api_key`, `pausatf_usatf_api_key` and `_api_secret`, `pausatf_strava_client_id` and `_client_secret`, `pausatf_garmin_consumer_key` and `_consumer_secret`, `pausatf_chronotrack_api_key`, `pausatf_mylaps_api_key`, `pausatf_webscorer_api_key`, `pausatf_racetab_api_key`. Uninstall deletes all of them. |
| `pausatf_sanctions_notification_email` | Retired. Uninstall deletes it. |

Uninstall removes the options listed in `uninstall.php` and the user meta holding Strava and Garmin tokens, and leaves every table and the schema options. See [upgrading.md](upgrading.md#uninstalling).

## Capabilities

RM checks `manage_options` for its admin screens, settings and the import routes. The Race Director Portal uses `pausatf_race_director`, which RM adds to the Administrator role and to a `race_director` role it creates (with `read` and `upload_files`).

## REST API

Namespace `pausatf/v1`. The routes marked public use `permission_callback => __return_true`.

| Method and route | Access | Feature | Purpose |
| --- | --- | --- | --- |
| `GET /events/{id}/results` | Public | REST API | Results for an event. Parameters: `division`, `limit` (default 100, maximum 500). |
| `GET /athletes/search?q=` | Public | REST API | Athlete search. `q` has a two-character minimum; `limit` default 20, maximum 50. |
| `GET /athletes/{name}/results` | Public | REST API | One athlete's results and stats by printed name. |
| `GET /leaderboard` | Public | REST API | Parameters: `division`, `year`, `limit` (default 50, maximum 100). |
| `GET /divisions`, `GET /seasons` | Public | REST API | Terms with results. |
| `POST /import` | `manage_options` | REST API | Parameter `url`: import one results page by URL. |
| `POST /events/import` | `manage_options` | REST API | JSON body: one canonical event record (`event_uid` and `name` required). Parameter `publish` (default false): without it the event is a draft. Returns the event ID, UID, result count and status. |
| `GET /rdf/{events\|athletes\|results\|ontology\|void}`, `GET /rdf/{type}/{id}` | Public | RDF and Linked Data | Parameter `format`: `turtle` (default), `rdfxml`, `jsonld` or `ntriples`. |
| `GET` and `POST /sparql` | Public | RDF and Linked Data | SPARQL query (`query`, `format`). |
| `GET /archive/releases/{id}`, `GET /archive/releases/{id}/{collection}` | Public, published releases only | Bundled archive | Collections: `performances`, `rankings`, `series_standings`, `association_records` and the other entity collections. `page` and `per_page` (maximum 100). |
| `GET /archive/review/{id}` | `manage_options` | Bundled archive | Private review view of a release. |

The archive routes exist only while the bundled archive is loaded, that is, while PAUSATF Site's Archive is not active.

The files `includes/api/class-rest-controller.php` and `includes/class-results-importer-v2.php` define classes that nothing loads. They are not part of the current surface.

### Other endpoints

| Endpoint | Feature | Notes |
| --- | --- | --- |
| `/pausatf-graphql` (rewrite rule) | GraphQL API (off by default) | Reads the query and variables from a JSON request body. Sends `Access-Control-Allow-Origin: *`. Queries: `events`, `event`, `athletes`, `athlete`, `results`, `records`, `rankings` and `grandPrix`. No mutations. The endpoint applies no authentication. |
| `/rdf/events`, `/rdf/athletes`, `/rdf/results`, each with `/{id}`, plus `/rdf/ontology` and `/rdf/void` | RDF and Linked Data (off by default) | Content-negotiated RDF. |
| `/sparql` | RDF and Linked Data | SELECT, CONSTRUCT, ASK and DESCRIBE, with a 10,000 row limit. |
| `admin-post.php?action=pausatf_archive_context` | Bundled archive | Administrator form handler. |

## Shortcodes

| Shortcode | Attributes (defaults) | Source |
| --- | --- | --- |
| `[pausatf_results]` | `event_id`, `year`, `type`, `division`, `limit` (100) | Core |
| `[pausatf_athlete]` | `id`, `name` | Core |
| `[pausatf_leaderboard]` | `division`, `year`, `limit` (25) | Core |
| `[pausatf_search]` | none | Core |
| `[pausatf_results_table]` | `event_id`, `year`, `type`, `division`, `filterable`, `sortable`, `exportable` (all `true`), `per_page` (50) | Core |
| `[pausatf_upcoming]`, `[pausatf_recent_results]` | `limit` (5), `type` | Core |
| `[pausatf_club_profile]` | `id`, `name` | Core (needs Club Manager) |
| `[pausatf_embed]` | `event_id`, `type` (`results`), `width` (100%), `height` (400), `theme` (`light`) | Core |
| `[pausatf_grand_prix]` | `series`, `year`, `division`, `gender`, `type`, `limit` | Grand Prix |
| `[pausatf_athlete_dashboard]` | none | Athlete Dashboard |
| `[pausatf_my_results]`, `[pausatf_claim_form]` | none | Athlete Self-Claim |
| `[pausatf_certificate_download]` | `result_id`, `template` (`finisher`), `text` | Certificates |
| `[pausatf_share_result]` | `result_id` | Certificates |
| `[pausatf_rd_portal]` | none | Race Director Portal |
| `[pausatf_archive_release]` | `id` | Bundled archive |
| `[pausatf_sanction_form]`, `[pausatf_my_sanctions]`, `[pausatf_sanctioned_events]` | ignored | Retired. Print a notice that links to PA's sanctions page. |

## WP-CLI

| Command | Purpose |
| --- | --- |
| `wp pausatf-results age-grading import <file>` | Validate and store an age-grading table. See [age-grading.md](age-grading.md). |
| `wp pausatf-results age-grading status` | Show the loaded edition. |
| `wp pausatf-results archive install\|stage\|inspect\|review\|publish\|withdraw` | The bundled archive workflow. Needs `--user=<admin>`. Not registered while PAUSATF Site's Archive is active. See [archive-context.md](archive-context.md). |
| `wp --require=tools/import-2026-calendar-events.php pausatf import-2026-calendars` | Imports the 2026 MUT and XC schedule sources as published events through `CanonicalEventService`. The command lives in `tools/import-2026-calendar-events.php`, which the plugin does not load itself. |

RM has no CLI command for Grand Prix, sanctions export or rankings. The sanctions export uses plain `wp db` commands; see [sanctions-retirement.md](sanctions-retirement.md).

## Cron

| Hook | Schedule | Purpose |
| --- | --- | --- |
| `pausatf_results_sync` | Scheduled on activation as `daily`; rescheduled when the sync settings change | Reads `PAUSATF_LEGACY_SOURCE_URL` for the current year and imports new `.html` files, skipping index, schedule, form, flyer and info pages. Does nothing unless `pausatf_sync_enabled` is on. |
| `pausatf_batch_import` | Single event queued from **Import > Batch Import by Year** | Imports every file for a year (1994 to 2025). |
| `pausatf_deliver_webhook` | Single event per delivery | Webhook delivery and retry (1 minute, 5 minutes, 30 minutes, 2 hours, 6 hours; five attempts). |
| `pausatf_sanction_reminders` | Retired | Cleared on the next `init` and on deactivation. |

A `weekly` schedule is added to WP-Cron.

## Admin AJAX actions

Settings tools, which require `manage_options` and the `pausatf_ajax` nonce: `pausatf_bulk_create_athletes`, `pausatf_reparse_all`, `pausatf_scan_records`, `pausatf_regenerate_rankings`, `pausatf_repair_tables`, `pausatf_clear_cache`, `pausatf_delete_all`. The other actions check their own nonce: `pausatf_claim_athlete`, `pausatf_unclaim_athlete` and `pausatf_verify_claim` (nonce `pausatf_claim`), `pausatf_dashboard_data`, `pausatf_export`, `pausatf_rd_upload`, `pausatf_rd_create_event`, `pausatf_rd_update_event`, `pausatf_strava_callback`. `pausatf_filter_results`, `pausatf_generate_certificate` and `pausatf_generate_share_card` are also registered for logged-out visitors.

## Hooks RM provides

### Filters

| Filter | Arguments | Purpose |
| --- | --- | --- |
| `pausatf_results_gp_member_status` | `null`, result row | Current PA membership: true, false or null. See [grand-prix.md](grand-prix.md). |
| `pausatf_results_gp_is_pa_club` | `null`, club, result row | Whether the club is a PA club. |
| `pausatf_results_gp_birth_date` | `null`, athlete key, result row | `Y-m-d` birth date for decade carry-over. |
| `pausatf_results_gp_team_division` | division, result row | Road team division. |
| `pausatf_results_xc_team_division` | division, result row | XC team division. |
| `pausatf_results_placement_time` | stored basis, event ID | `gun`, `net` or `combination` for an event. |
| `pausatf_results_placement_time_combination` | `gun`, provider fields | `gun` or `net` for one result. |
| `pausatf_results_age_rules` | rules array | Open, masters and youth age rules per discipline. |
| `pausatf_results_defer_archive_to_site` | detected boolean | Force the archive to defer to PAUSATF Site, or not. |
| `pausatf_results_sanctions_url` | URL | Link in the retired sanctions notice. |
| `pausatf_find_athlete` | `null`, result row | Match an imported result to an athlete post ID. Used by `ResultsRepository` imports. |

### Actions

| Action | Arguments | Fired when |
| --- | --- | --- |
| `pausatf_results_age_grading_imported` | edition | An age-grading table is stored. |
| `pausatf_new_record` | record ID, record data | A record is approved. |
| `pausatf_event_created`, `pausatf_event_updated` | event ID, parsed results, metadata | `ResultsRepository::store_event()` creates or updates an event (canonical import). |
| `pausatf_results_stored` | event ID, storage result | `ResultsRepository` stores results. |
| `pausatf_results_deleted` | event ID, count | `ResultsRepository` deletes an event's results. |
| `pausatf_set_event_taxonomies` | event ID, parsed results, metadata | `ResultsRepository` assigns taxonomies. |

`ResultsRepository` runs for the canonical event import (`POST /events/import` and the calendar command). The admin import, the sync and `POST /import` use `ResultsImporter`, which fires none of these actions.

### Actions RM listens for and never fires

These hooks have listeners in RM but nothing in RM triggers them, so they have effect only when another plugin or site code fires them:

| Action | Listener | Effect |
| --- | --- | --- |
| `pausatf_result_imported` (event ID, count) | Grand Prix | Recomputes every series containing the event. |
| `pausatf_results_imported` (event ID, count) | Webhooks | Sends `result.created`. |
| `pausatf_athlete_claimed` (athlete ID, user ID) | Webhooks | Sends `athlete.claimed`. |
| `pausatf_rankings_updated` (event, count) | Webhooks | Sends `ranking.updated`. |

Webhooks do send `event.created` and `event.updated` (on `save_post_pausatf_event`) and `record.created` (on `pausatf_new_record`). The events `result.updated` and `record.approved` can be selected in the admin screen, but nothing triggers them. Webhook payloads are signed with HMAC-SHA256 in the `X-PAUSATF-Signature` header (`sha256=<hex>`), with `X-PAUSATF-Event` and `X-PAUSATF-Delivery` headers.

`ResultsImporterV2` declares `pausatf_pre_parse_html`, `pausatf_validate_parsed_results`, `pausatf_should_skip_import_file`, `pausatf_batch_import_file_processed` and `pausatf_import_completed`. Nothing loads that class, so these hooks do not run.
