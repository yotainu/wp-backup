=== WP pCloud Backup ===
Contributors: yogawa
Tags: backup, pcloud, database, files, cron
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up your WordPress files and database to pCloud on a schedule. Built to finish on shared hosting.

== Description ==

WP pCloud Backup archives your site files (ZIP64, split into parts) and a gzip-compressed SQL dump of the whole database, and uploads them to your pCloud account. The work is split into small steps so that it completes on shared hosting with strict PHP time limits.

* Schedules: daily, every 3 days, weekly, every 2 weeks or monthly.
* Keeps the newest 1 to 30 generations and deletes older ones automatically.
* Emails you when a backup fails (optionally on success too).
* Runs from WP-Cron or from an external cron job using a secret URL.
* Can be pre-configured from wp-config.php, which helps when you manage many sites.
* Updates itself from GitHub Releases, with SHA-256 verification of the package.

Backups in pCloud are never deleted when you uninstall the plugin.

A restore guide (merging the split ZIP, importing the database) is included in the README on GitHub.

== Installation ==

1. Upload the `wp-pcloud-backup` folder to `/wp-content/plugins/`, or install the release zip from the Plugins screen.
2. Activate the plugin. It requires PHP 7.4 or later with the zlib, curl and openssl extensions.
3. Open Settings > pCloud Backup > Connection and connect your pCloud account (paste an access token, or exchange an authorization code), or define `WP_PCLOUD_BACKUP_ACCESS_TOKEN` in wp-config.php.
4. Choose the schedule and number of generations on the Settings tab.
5. Press "Backup now" on the Status tab to run the first backup and check that the files arrive in pCloud.

== Frequently Asked Questions ==

= Where are the backups stored? =

In pCloud under `/WPBackup/<site folder>/YYYY-MM-DD_HHMMSS/`, as `manifest.json`, `database.sql.gz` and `files.zip.001`, `files.zip.002`, and so on.

= How do I restore? =

Download the dated folder, join the parts (`cat files.zip.* > files.zip` on macOS and Linux), unzip, upload the files to the path recorded in `manifest.json`, then import `database.sql`. See the README for details.

= Is it safe to use one pCloud token on many sites? =

All sites sharing a token can reach the whole pCloud account. Use a pCloud account dedicated to backups, and never share the backup folders because database dumps contain password hashes.

== Changelog ==

= 0.1.0 =
* First release.
