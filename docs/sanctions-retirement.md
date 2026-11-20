# Sanctions Manager retirement

USATF grants sanctions. Event directors apply online at usatf.org, normally at least 30 days before the event (2026 USATF Governance Handbook, Regulation 23-C). Results Manager's Sanctions Manager duplicated that workflow with its own fee table, which matched no published USATF or PA fee schedule. It has been removed.

## What changed

The plugin no longer loads any part of the manager:

- the **PAUSATF Results → Sanctions** admin page;
- the `pausatf_*_sanction*` AJAX actions and the sanctions REST routes;
- the public sanction form, "my sanctions" and sanctioned-events views, and their stylesheet;
- the fee calculator and the notification emails;
- the daily `pausatf_sanction_reminders` cron job, which is unscheduled on the next page load and on deactivation;
- the **Event Sanctions Manager** entry on the Features screen.

`[pausatf_sanction_form]`, `[pausatf_my_sanctions]` and `[pausatf_sanctioned_events]` still resolve, so pages that contain them do not show raw shortcode text. They print a notice that sanctions are handled through USA Track & Field, linked to PA's sanctions page. Change the link with the `pausatf_results_sanctions_url` filter, or edit those pages to remove the shortcodes.

The race director portal's "USATF Sanctioned?" field on events is unrelated and unchanged.

## What was kept

Nothing stored by the manager was deleted:

| Data | Where |
|---|---|
| Sanction applications | `{prefix}pausatf_sanctions` |
| Certificate of insurance requests | `{prefix}pausatf_sanction_coi` |
| Post-event reports | `{prefix}pausatf_sanction_reports` |
| Change history | `{prefix}pausatf_sanction_history` |
| Notification address | option `pausatf_sanctions_notification_email` |
| Certificate files named in `certificate_file` | wherever they were stored, usually the uploads directory |
| Role capabilities | `manage_sanctions`, `review_sanctions`, `submit_sanctions`, `view_own_sanctions` on Administrator, Editor and Subscriber |

`{prefix}` is the site's table prefix, usually `wp_`. Uninstalling the plugin removes the option, as before, and leaves the tables, as it does for all result data.

## Export the records

Run from the WordPress root with WP-CLI. Keep the files with the association's records.

```sh
prefix="$(wp db prefix)"
for t in sanctions sanction_coi sanction_reports sanction_history; do
  wp db query "SELECT * FROM ${prefix}pausatf_${t}" > "pausatf_${t}.tsv"
done
wp db export pausatf-sanctions.sql --tables="${prefix}pausatf_sanctions,${prefix}pausatf_sanction_coi,${prefix}pausatf_sanction_reports,${prefix}pausatf_sanction_history"
```

The `.sql` file restores the tables exactly; the `.tsv` files are for reading in a spreadsheet. Some rows hold contact details for event directors, so store the exports where personal data is allowed.

## Remove the records later

Only after the export is verified and the association agrees the records are no longer needed:

```sh
prefix="$(wp db prefix)"
wp db query "DROP TABLE IF EXISTS ${prefix}pausatf_sanction_history, ${prefix}pausatf_sanction_reports, ${prefix}pausatf_sanction_coi, ${prefix}pausatf_sanctions"
wp option delete pausatf_sanctions_notification_email
for role in administrator editor subscriber; do
  for cap in manage_sanctions review_sanctions submit_sanctions view_own_sanctions; do
    wp cap remove "$role" "$cap" 2>/dev/null || true
  done
done
```

On multisite, repeat for each site with `--url=<site>`.
