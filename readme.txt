=== PAUSATF Results Manager ===
Contributors: thomasvincent
Tags: results, athletics, track-and-field, race-results, importer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import, manage, and display track & field and road-racing competition results with athlete tracking.

== Description ==

PAUSATF Results Manager imports competition results from many legacy HTML
formats (tables, fixed-width PRE blocks, Word-generated markup) spanning
decades, normalizes them, and links performances to athlete profiles. It
provides shortcodes and a REST API for displaying results, leaderboards, and
athlete pages.

Features include athlete search, per-event results tables, grand-prix scoring,
records, and (optionally) finisher certificates.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it through the Plugins screen.
3. Configure options and import sources under the Results admin menu.

== Frequently Asked Questions ==

= Does uninstalling delete my imported results? =

No. Uninstall removes only plugin options. Result data is preserved so it
cannot be lost by an accidental deletion.

== Changelog ==

= 2.2.0 =
* Security hardening for certificate/share-card generation; DOM-safe athlete
  search; TLS verification enabled on imports.
