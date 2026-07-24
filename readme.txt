=== Storage Sherpa ===
Contributors: magepeopleteam
Tags: storage, cleanup, database, media, optimization
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The smart WordPress storage optimizer. Scan the whole install, find orphan/duplicate media, clean the database, and reclaim disk space — safely.

== Description ==

Storage Sherpa helps WordPress users reclaim disk space, clean unnecessary data, optimize databases, and improve website performance safely.

Unlike traditional cleanup plugins that simply delete revisions or optimize tables, Storage Sherpa analyzes the entire WordPress installation and lets you safely review every item before removing it.

**Safety comes first.** Every deletion — a file, a database row, or a whole table — is moved to a Safe Trash first and stays restorable until its retention window (default 15 days) closes. Nothing is permanently removed without that window passing or an explicit "Delete Permanently" click.

= What's included in this build =

* Storage Analyzer — scan the whole install by category, largest directories, storage trend history
* Orphan Media Scanner — find attachments not referenced anywhere (content, meta, widgets, theme options)
* Duplicate Media Finder — hash-based duplicate detection
* Large File Scanner
* Image Optimization — compress, generate WebP/AVIF (when the server supports it), remove unused thumbnail sizes, regenerate thumbnails
* Empty Folder Cleaner
* Broken Media detection + reconnect/delete
* Database Cleanup — revisions, drafts, trash, spam, orphan meta, expired transients, and more
* Orphan Database Tables (manual review + backup-before-drop)
* Backup Cleanup — UpdraftPlus, Duplicator, Solid Backup/BackupBuddy, BackWPup, All-in-One WP Migration, WPvivid
* Cache Cleaner — WP Rocket, W3 Total Cache, LiteSpeed, WP Fastest Cache, Breeze, SG Optimizer, FlyingPress, object cache, OPcache
* Log Cleaner
* Cron Manager
* Autoload Option Analyzer
* File Type Analyzer
* Recovery Center (Safe Trash)
* Scheduled Scans + email notifications
* Ignore Rules
* Background (chunked) scanning — no server timeouts
* REST API powering the dashboard

See `CLAUDE.md` in the plugin folder for full architecture notes and what's deferred to a later build.

== Installation ==

1. Upload the `storage-sherpa` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Storage Sherpa → Dashboard and click "Run Full Scan".

== Changelog ==

= 1.0.0 =
* Initial build.
