=== PAUSATF Results Manager ===
Contributors: thomasvincent
Tags: results, athletics, track-and-field, race-results, importer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.4
Stable tag: 2.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import, manage, and display track & field and road-racing competition results with athlete tracking, USATF classification rules and Pacific Association Grand Prix standings.

== Description ==

PAUSATF Results Manager imports competition results from legacy HTML formats (tables, fixed-width text blocks, Word-generated markup) spanning decades, normalizes them, and links performances to athlete profiles. It provides shortcodes and a REST API for results, leaderboards and athlete pages.

It applies rules from the 2026 USATF Competition Rules and the 2026 Pacific Association documents:

* Age classifications and masters divisions (Rules 141 and 320), by discipline.
* Sex stored exactly as the results list it. A value other than M or F is shown, not scored.
* Gun or net placement time as the event states it, with official times rounded up (Rules 165.11 and 165.16).
* Road record course limits (Rule 265.4).
* Grand Prix standings for the PA road, cross country and MUT rules, with team scoring. Standings are unofficial until membership and club data are supplied by the site.
* Age-graded rankings, which stay off until an administrator imports an age-grading table. The plugin ships none.

Other features include athlete search, finisher certificates, records, rankings, and optional GraphQL, RDF and SPARQL endpoints. Features are switched on and off under Settings. Full documentation is in the plugin's docs folder on GitHub.

The plugin works next to PAUSATF Site. When that plugin's Events module is active it owns the event post type, and when its Archive module is active this plugin's own archive does not load.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it through the Plugins screen.
3. Choose features and configure sync under PAUSATF Results > Settings, and import results under PAUSATF Results > Import.

== Frequently Asked Questions ==

= Does uninstalling delete my imported results? =

No. Uninstall removes the plugin's options, including stored API keys and secrets, and the per-user Strava and Garmin tokens. Result data, Grand Prix data and retired sanction data stay in their database tables, so an accidental deletion cannot lose them. Drop the tables by hand for a full removal.

= What happened to the Sanctions Manager? =

It was retired. USATF takes sanction applications online, so the plugin no longer loads the manager. Existing sanction records are not touched. The three old sanction shortcodes show a notice that links to PA's sanctions page. The documentation explains how to export or remove the old data.

= Why are age-graded rankings empty? =

The plugin ships no age-grading factors. Import a factor table with `wp pausatf-results age-grading import <file>`, then enable Age-Graded Rankings under Settings > Features.

= Are Grand Prix standings official? =

No. They are computed from the PA rule texts and marked unverified until the site supplies membership, club and birth-date data. Compare them with the association's published standings.

== Upgrade Notice ==

= Unreleased =
Schema changes run on the first wp-admin page load and delete nothing. Read docs/upgrading.md before upgrading a production site.

== Changelog ==

= Unreleased =
* Event dates read `_pausatf_event_start` first and fall back to `_pausatf_event_date`.
* Age divisions follow Rules 141 and 320 per discipline; U20 is a date test.
* Sex is stored as listed in a new `sex_listed` column; unknown values are never scored as men.
* Road record course limits (Rule 265.4) are available as a helper.
* Placement time follows the event's stated gun, net or combination basis, gun by default. Official times round up to the next whole second.
* The Sanctions Manager is retired. Its data is kept.
* Age grading uses one implementation and a factor table imported with WP-CLI. Rankings stay off without a table.
* Cross country team scoring follows the PA Cross Country Rules (3/1/26).
* Athlete identity resolves through PAUSATF Site's Archive, with a fallback.
* Delete All Data empties exactly the tables RM creates and never deletes events owned by PAUSATF Site.
* Grand Prix standings for the PA 2026 road, cross country and MUT rules, with a `ruleset` column on series.
* The bundled results archive (admin menu, `pausatf/v1/archive/*` REST routes, `pausatf_archive_release` shortcode, `pausatf-results archive` CLI) is not loaded while PAUSATF Site's Archive module is active. Use the `pausatf_results_defer_archive_to_site` filter to force either behavior.

= 2.4.0 =
* Defers the `pausatf_event` post type to PAUSATF Site when its Events module is active, and keeps canonical event imports publishable.

= 2.2.0 =
* Security hardening for certificate/share-card generation; DOM-safe athlete search; TLS verification enabled on imports.
