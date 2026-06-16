=== 404 to 301 - Redirects Importer ===
Contributors: joelcj91, duckdev
Tags: redirect, import redirects, csv import, redirection import, 404 to 301
Donate link: https://www.paypal.me/JoelCJ
Requires at least: 6.4
Tested up to: 6.5
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Bulk-import custom redirects into 404 to 301 from CSV files — or migrate them straight in from the Redirection and 301 Redirects plugins. No manual re-entry.

== Description ==

**404 to 301 – Redirects Importer** is the official bulk-import add-on for the [404 to 301](https://wordpress.org/plugins/404-to-301/) plugin. It adds an **Import** panel to the Tools tab so you can load hundreds — or thousands — of custom redirects in one go, either from a CSV file you already have or directly from another redirect plugin you're moving away from. Every redirect status the parent plugin supports (301, 302, 307 and more) is preserved on import.

Use it when you're migrating a site, switching redirect plugins, restoring a backup, or rolling out a planned URL change across a large content set. Stop copy-pasting redirects one row at a time.

= Why use Redirects Importer? =

* **CSV import** — bring redirects in from a spreadsheet, a backup, or another tool that can export CSV.
* **Plugin migration** — import redirects directly from popular plugins:
  * **Redirection** by John Godley
  * **301 Redirects** by Webcraftic
* **Smart matching** — exact, prefix and regex matches are detected and preserved, so your existing rules keep behaving the same way.
* **All redirect statuses preserved** — 301, 302, 307 and the other codes the parent plugin supports come through intact, not flattened to 301.
* **Safe by default** — duplicate redirects are skipped, invalid rows are reported, and nothing overwrites your existing rules unless you choose to.
* **Native integration** — rows are written through the parent plugin's own model, so hashing, audit events, hit counters and dedupe rules stay consistent with single-row creates.
* **Free and unlimited** — no row limits, no premium upgrade.

= Built for the 404 to 301 workflow =

This add-on is a light-weight companion to the parent plugin. It hooks into the existing Settings → Tools screen and re-uses the same database tables and validation rules, so imported redirects behave identically to redirects you create by hand.

* No new settings page — the Import panel lives inside the existing Tools tab.
* Requires the free [404 to 301](https://wordpress.org/plugins/404-to-301/) plugin (4.0 or newer).
* Same coding standards, security model and multisite behaviour as the parent plugin.

= Related add-ons =

Browse the full add-ons catalogue at [https://duckdev.com/addons/404-to-301/](https://duckdev.com/addons/404-to-301/):

* **Logs Exporter** — Export the 404 error log table as a downloadable CSV.
* **Logs Cleaner** — Auto-prune the 404 log table by age, row count or schedule.
* **Email Reports** — Periodic email digests of your 404 activity with an attached CSV.

== Source code & contributions ==

* **GitHub repository:** [https://github.com/duckdev/404-to-301-redirects-importer](https://github.com/duckdev/404-to-301-redirects-importer)
* **Documentation:** [https://docs.duckdev.com/404-to-301/addons/redirects-importer/](https://docs.duckdev.com/404-to-301/addons/redirects-importer/)
* **Support forum:** [https://wordpress.org/support/plugin/404-to-301-redirects-importer/](https://wordpress.org/support/plugin/404-to-301-redirects-importer/)

Pull requests and bug reports are welcome on GitHub.

== Installation ==

1. Make sure the free [404 to 301](https://wordpress.org/plugins/404-to-301/) plugin (version 4.0 or newer) is installed and activated.
2. Install **404 to 301 – Redirects Importer** from the WordPress.org plugin directory, or upload the plugin folder to `/wp-content/plugins/`.
3. Activate the add-on from the **Plugins** screen.
4. Open **404 to 301 → Settings → Tools**, choose your source (CSV file or another plugin), and start the import.

== Frequently Asked Questions ==

= Do I need the 404 to 301 plugin installed? =

Yes. This is an add-on for the free [404 to 301](https://wordpress.org/plugins/404-to-301/) plugin (4.0 or newer). Without it, there's nowhere for the imported redirects to live.

= Which redirect plugins can I migrate from? =

You can import redirects directly from **Redirection** (by John Godley) and **301 Redirects** (by Webcraftic). You can also import any CSV that follows the documented column format — see the [documentation](https://docs.duckdev.com/404-to-301/addons/redirects-importer/) for the full schema. More source plugins may be added in future releases.

= What CSV format does it expect? =

A simple, documented column layout: source URL, destination URL, match type (exact / prefix / regex), redirect type (301, 302, 307, ...) and an optional enabled flag. A sample CSV is linked from the Import panel and the [documentation](https://docs.duckdev.com/404-to-301/addons/redirects-importer/).

= What happens to duplicate redirects? =

Duplicates are detected and skipped, so importing the same file twice is safe — your existing redirects stay untouched.

= What happens to invalid rows? =

Invalid rows are reported back to you after the import so you can fix the source file. The rest of the file still imports successfully.

= Is there a row limit? =

No. The importer streams rows in batches, so even large files (tens of thousands of rows) import without hitting memory or timeout limits on typical hosts.

= Does it support multisite? =

Yes. Imports run per-site, so each site in the network gets its own redirects.

= Where can I get help? =

Read the [documentation](https://docs.duckdev.com/404-to-301/addons/redirects-importer/) or post on the [support forum](https://wordpress.org/support/plugin/404-to-301-redirects-importer/).

== Screenshots ==

1. The Import panel on the Tools tab — pick a CSV file or another plugin to migrate from.
2. Import summary showing imported, skipped and invalid rows.

== Changelog ==

= 1.0.0 =
* New: Initial release. CSV import plus direct migration from the Redirection and 301 Redirects plugins.

== Upgrade Notice ==

= 1.0.0 =
First public release of the Redirects Importer add-on for 404 to 301.
