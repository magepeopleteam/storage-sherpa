# Storage Sherpa — The Smart WordPress Storage Optimizer

Status: **20 of 25 spec modules built, lint-clean, and verified activating + running against a real WordPress
install** (see [Verification status](#verification-status) — 3 real bugs were found and fixed in the
process). **Not yet built, by explicit user request**: Module 10 (Plugin Cleanup), Module 11 (Theme Cleanup),
Module 18 (Security Cleanup), Module 21 (Reports/PDF-CSV export), Module 25 (WP-CLI). See
[Not yet built](#not-yet-built) for what that means concretely and what's reserved for them. The dev site
this was verified against has no media library content, so several modules were only confirmed to run
error-free on an empty result set, not against real duplicate/broken/oversized files — see the last section of
[Verification status](#verification-status) for exactly what that leaves genuinely unverified.

## Vision

Storage Sherpa helps WordPress users reclaim disk space, clean unnecessary data, optimize databases, and
improve website performance safely. Unlike traditional cleanup plugins that simply delete revisions or
optimize tables, it analyzes the entire WordPress installation and lets the user review every item before
removing it. **Safety comes first** — nothing is permanently deleted without passing through the pipeline
below.

```
Scan → Review → Backup → Move to Safe Trash → Restore Available → Permanent Delete
```

The full 25-module product spec (dashboard mockup, all module descriptions, Free/Pro split, roadmap through
v3.0) was supplied as the original brief for this plugin. This document tracks what's actually real in code
today against that brief — treat the spec as the long-term target, this file as ground truth.

## Reference plugins

File/folder conventions are modeled on the sibling plugins in this environment — `passpress` in particular
(flat `admin/` + `inc/` split, `inc/modules/{module}/class-ss-*.php` naming, one `CLAUDE.md` tracking ground
truth against a large spec, a single settings screen with tabbed sections rather than N separate pages). See
`../passpress/wp-content/plugins/passpress/CLAUDE.md` for the pattern this file follows.

## Core safety mechanism: Safe Trash (`inc/SS_Trash.php`)

Every module that deletes anything routes through `SS_Trash`, never `unlink()`/`$wpdb->delete()` directly.
Three trash item types, one recovery UI (Recovery Center):

- `file` — the physical file is *moved* (not copied) into `wp-content/storage-sherpa-trash/{timestamp}-{rand}/`
  preserving its relative path. Restore moves it back; permanent delete unlinks it. The trash directory has
  its own `index.php` + `.htaccess` (`Deny from all`) so trashed files are never reachable by a bare URL.
- `db_row` — used by Database Cleanup, Broken Media, and the shared `SS_Trash::trash_attachment()` helper.
  The row is JSON-encoded into the trash entry *before* the caller deletes it from its live table; restore
  is `$wpdb->replace()` back into the original table. This **is** the "automatic database backup" the spec
  calls for — there's no separate mysqldump step, the trash row is the backup.
- `table_dump` — Module 9 only. Captures `SHOW CREATE TABLE` + up to 5,000 rows (partial-backup warning
  shown if the table is bigger) before `DROP TABLE`; restore re-runs the `CREATE TABLE` and bulk-inserts
  the rows back.

Retention (`Settings → General`, default 15 days) is enforced by `storage_sherpa_trash_sweep_event`
(daily WP-Cron → `SS_Trash::sweep_expired()`), not by the individual modules.

**Deliberate exceptions that do NOT route through Safe Trash** (each documented at the call site, not an
oversight):
- **Module 6 (Empty Folder Cleaner)** — an empty directory holds no data; there's nothing to restore.
- **Module 13 (Cache Cleaner)** and the generic `wp-content/cache` folder wipe — cache is regenerable by
  definition; the owning plugin rebuilds it on the next request.
- **Module 15 (Cron Manager)** delete — cron event metadata, not user data; a plugin that still needs the
  schedule re-registers it on its own init/activation hook.
- **Module 16 (Autoload Analyzer)** "disable autoload" — flips `wp_options.autoload` to `no`; the option and
  its value are completely untouched, so toggling it back to `yes` *is* the undo.
- **Module 5 (Image Optimization) `regenerate_thumbnails()`** — idempotent by nature (regenerates from the
  untouched full-size original), so there's nothing meaningful to snapshot beforehand. `compress()`, by
  contrast, *does* go through Safe Trash (via a temp-copy trick, since `trash_file()` moves rather than
  copies and the file being edited is the same one just about to be overwritten).

## What's actually built

### Module 1 — Storage Analyzer (`inc/modules/analyzer/class-ss-storage-analyzer.php`)
Scans 7 categories (Uploads, Database, Plugins, Themes, Cache, Logs, Backups), each saved as its own row in
`{prefix}ss_scan_snapshots` per scan — that one table is both "today's dashboard numbers" (latest row per
scope) and the 30-day growth trend (grouped by date). Logs/Backups totals delegate to Modules 14/12 rather
than re-implementing detection. `calculate_health_score()` is an explicitly-documented heuristic (starts at
100, subtracts for recoverable-space ratio, large logs, orphan table count, trash backlog) — not a scientific
metric.

### Modules 2, 3, 4, 7 — Media findings (`inc/modules/media/`)
Share one table, `{prefix}ss_media_findings`, disambiguated by `finding_type` (`orphan`/`duplicate`/`large`/
`broken`) via the shared `SS_Media_Findings` data-access class — one CRUD/query surface instead of four
near-identical tables.

- **Orphan Media Scanner** (`class-ss-orphan-media-scanner.php`) — definitive sources (featured image,
  WooCommerce gallery meta, `[gallery]` shortcode ids, a literal resolved upload URL, site icon, custom logo,
  media widgets) mark an attachment `used`. Everything else — **this is the one deviation from the spec worth
  understanding** — is checked with a single generic regex pass over serialized/JSON postmeta and options
  text (`wp-image-N` classes, `attachment_N`, bare `"id":N` patterns, and any `uploads/...` URL, resolved
  through a size-aware URL→attachment-id map). That one generic pass is what stands in for eight separate
  bespoke parsers for ACF, Elementor, Bricks, Oxygen, Beaver Builder, Meta Box, JetEngine, and Customizer
  theme_mods — **none of those plugins are installed in this environment** to build/verify real integrations
  against, so a shared heuristic was the honest choice over eight unverifiable stubs. A URL match through this
  path still counts as `used` (it's a real resolved path); a bare id-pattern match with no URL confirmation is
  marked `possibly_used`, never `used` — so a heuristic false positive can never be silently treated as safe
  to delete. Nav menu items are covered for free (they're `nav_menu_item` posts, and post_content is scanned
  for all post types).
- **Duplicate Media Finder** (`class-ss-duplicate-finder.php`) — groups by exact file size first (cheap),
  only hashes files that share a size with something else (SHA-256 default, MD5 offered). Oldest file in a
  hash group is `original`, the rest are `duplicate`.
- **Large File Scanner** (`class-ss-large-file-scanner.php`) — walks all of `wp-content`, not just uploads
  (the spec's extension list includes `.sql`/`.log`/`.zip`/`.iso`, which live in backups/cache/logs too).
- **Broken Media** (`class-ss-broken-media.php`) — attachment row exists, file doesn't. `suggest_reconnect()`
  only offers a fix when exactly one same-named file is found anywhere under uploads (ambiguous matches are
  left for the admin to resolve manually rather than guessed).

`SS_Trash::trash_attachment()` (in `inc/SS_Trash.php`, not a media-module file — it's shared) is the one
"delete an attachment" implementation all four findings types and the media admin screen call: backs up the
post row + all postmeta, moves the base file *and every registered thumbnail size* to Safe Trash, then removes
the now-fileless post.

### Module 5 — Image Optimization (`inc/modules/media/class-ss-image-optimizer.php`)
Built entirely on `wp_get_image_editor()` (GD or Imagick, whichever the server has) — no bundled compression
binaries. Three genuinely different operations, kept distinct because they affect disk usage differently:
`compress()` re-encodes in place (the only one that actually shrinks this attachment's footprint, routed
through Safe Trash); `generate_webp()`/`generate_avif()` add a *sibling* file for `<picture>`-style delivery
(improves visitor page-weight, doesn't reduce this attachment's stored bytes); `remove_unused_thumbnails()`
deletes on-disk size files that don't match any currently-registered image size (a real, common leftover after
a theme switch). AVIF is feature-detected (`imageavif()` needs PHP 8.1+ GD or a libheif Imagick build) and
returns a clear error rather than pretending to encode on servers that can't.

### Module 6 — Empty Folder Cleaner (`inc/modules/filesystem/class-ss-empty-folder-cleaner.php`)
Bottom-up scan (`RecursiveIteratorIterator::CHILD_FIRST`) so a folder that's only empty *after* its child was
removed still gets caught in the same pass. Protects `wp-content`, `wp-content/plugins`, the theme root, the
uploads base dir, and the Safe Trash dir itself from ever being removed even if they're transiently empty.

### Modules 8, 9 — Database Cleanup (`inc/modules/database/`)
14 categories via one generic per-category engine (`class-ss-database-cleanup.php`): revisions, auto-drafts,
trashed posts/pages, spam/trash comments, trackbacks/pingbacks, expired transients (paired
`_transient_`/`_transient_timeout_` cleanup), orphan post/comment/user/term meta, orphan term relationships,
Elementor CSS cache (`_elementor_css` postmeta — safe, Elementor regenerates it), Rank Math/Yoast transient
cache, plus WooCommerce sessions and Action Scheduler completed-actions/orphan-logs **when those tables
actually exist** (checked via `SHOW TABLES LIKE`, not assumed). Table maintenance (OPTIMIZE/REPAIR/ANALYZE)
is separate — it doesn't delete rows, so it isn't part of the Safe Trash pipeline.

**Two spec bullets deliberately NOT implemented here, on purpose, not by oversight:**
- *"Orphan options"* — there's no registry mapping an option to the plugin that owns it, so a generic rule
  risks deleting a live setting. Reserved for Module 10's curated per-plugin approach instead (see
  [Not yet built](#not-yet-built)).
- *"WooCommerce logs"* — those are files under `uploads/wc-logs/`, not DB rows. Module 14 (Log Cleaner)
  detects them by path so log-file handling stays in one place.
- *"Temporary tables"* — that's whole-table detection, which is Module 9's job, not a row-delete category.

**Module 9 (`class-ss-orphan-tables.php`)** is a best-effort heuristic, explicitly documented as such: any
`{prefix}`-owned table that isn't a known WordPress core table and doesn't contain any currently-active
plugin's or theme's slug as a substring gets listed, with "estimated plugin" being a guessed fragment. Never
auto-deleted — the spec calls this "delete manually," and `drop_table()` requires an explicit per-row
confirm + takes a full schema+data backup first.

### Module 12 — Backup Cleanup (`inc/modules/backups/class-ss-backup-cleanup.php`)
Glob-based detection against each of the six named plugins' documented default backup folder (including
BackWPup's randomized-suffix folder, matched with a wildcard). **None of these six plugins are installed in
this environment** to verify against directly — detection targets each plugin's publicly documented default
path rather than parsing that plugin's own manifest format, the same honest-best-effort approach as Module 9.

### Module 13 — Cache Cleaner (`inc/modules/cache/class-ss-cache-cleaner.php`)
Each integration calls that plugin's own real, documented public purge function/hook (`rocket_clean_domain()`,
`w3tc_flush_all()`, the `litespeed_purge_all` action, `WpFastestCache::deleteCache()`, etc.) — never a guess
at a cache plugin's folder layout. `available_targets()` only lists a plugin if it's actually detected active
on the current site (function/class/hook existence checks), plus `wp_using_ext_object_cache()` for a
persistent object cache and `opcache_reset()` when OPcache is enabled. A generic `wp-content/cache` folder
wipe is always offered as a fallback.

### Module 14 — Log Cleaner (`inc/modules/logs/class-ss-log-cleaner.php`)
Only scans inside `WP_CONTENT_DIR` — real Apache/NGINX server logs normally live at `/var/log/...` outside
the docroot entirely, genuinely out of reach (and usually out of file-permission) for a WordPress plugin, so
that part of the spec bullet is honestly out of scope rather than faked. Also covers WooCommerce's
`uploads/wc-logs/*.log` files (see the Module 8 note above for why that's here, not there).

### Module 15 — Cron Manager (`inc/modules/cron/class-ss-cron-manager.php`)
Thin layer over `_get_cron_array()`. WordPress itself never records whether a past cron run succeeded, so
"failed"/"overdue" here is an explicit heuristic — a scheduled timestamp more than an hour in the past — not
a certainty. Delete is metadata-only (see the Safe Trash exceptions list above).

### Module 16 — Autoload Option Analyzer (`inc/modules/database/class-ss-autoload-analyzer.php`)
Lists the largest `autoload = 'yes'` options with a best-guess "owner" (matched against active plugin slugs,
falling back to a WordPress-core/widgets or active-theme guess). "Disable autoload" only flips the column —
see the Safe Trash exceptions list.

### Module 17 — File Type Analyzer (`inc/modules/filesystem/class-ss-filetype-analyzer.php`)
Buckets every file under `wp-content` into the spec's named categories (Images, Videos, PDFs, ZIP, Logs,
Fonts, Documents) plus `unknown` for everything else, including audio — the spec's category list doesn't
call out audio separately, so it wasn't given its own bucket.

### Module 19 — Recovery Center
Is `inc/SS_Trash.php` + `admin/SS_Recovery_Page.php` — see [Core safety mechanism](#core-safety-mechanism-safe-trash-incss_trashphp) above. No separate module directory; the mechanism *is* the module.

### Module 20 — Scheduled Scans (`inc/SS_Cron.php`)
Daily/weekly/monthly cadence (Settings), each running `SS_Storage_Analyzer::run_full_scan()` plus the other
scan modules, diffing against the previous snapshot to decide whether to fire Module 22 notifications, then
optionally running auto-cleanup **only** for categories explicitly opted into `Settings → Scheduled Scans →
Auto cleanup** — nothing runs unattended by default, matching "nothing is deleted automatically" from the
spec's Core Principles.

### Module 22 — Notification Center (`inc/SS_Notifications.php`)
One `send()` wrapping `wp_mail()` (matches the passpress `PP_Notifications` shape — the one place a future
channel would plug in) plus 5 trigger methods (growth, DB growth, orphan count, large logs, backup
accumulation) called from `SS_Cron` after each scheduled scan, each gated on its own configurable threshold.

### Module 23 — Ignore Rules (`inc/SS_Ignore_Rules.php`)
The one gate every scanner/cleanup module calls through (`storage_sherpa_is_ignored_path()` →
`SS_Ignore_Rules::is_ignored()`), so "never touch this folder/file/extension/table/attachment/plugin/theme"
is enforced in exactly one place rather than re-checked ad hoc per module.

### Module 24 — Background Scanner (`inc/SS_Background_Process.php`)
A full scan is broken into small steps (one module each), driven by the browser polling
`/scan/start` → repeated `/scan/step` calls via REST — every single request only ever runs one bounded step,
so there's no long-running request to time out. State lives in a transient (1-hour TTL) keyed by a generated
`job_id`, not a DB table — a scan job is inherently disposable; if the tab closes mid-scan the job just
expires, and a reloaded page can resume the same `job_id` via `get_status()`.

### REST API (`inc/SS_REST_API.php`)
Namespace `storage-sherpa/v1`. Every route shares one `permission_callback`
(`storage_sherpa_current_user_can()`, gated on the filterable `storage_sherpa_capability()`, default
`manage_options`) and is a thin pass-through to the module class that does the real work — the REST layer,
the admin screens, and (eventually) WP-CLI all call the *same* methods, so there's exactly one implementation
of each behavior.

## Frontend: React without a build step

The spec calls for React + WordPress Components + Tailwind CSS + Chart.js. This build makes the same call the
sibling `passpress` plugin made for its Gutenberg blocks, applied to the whole admin area:

- **Dashboard only** (`assets/admin/js/storage-sherpa-dashboard.js`) is a real `wp.element` (React, bundled
  with WordPress core — `@wordpress/element`/`@wordpress/components`/`@wordpress/api-fetch`, zero npm/webpack
  needed) app, hand-written with `createElement` rather than JSX. This genuinely satisfies "React +
  WordPress Components" without adding a build pipeline this environment can't run/verify.
- **Every other screen** (Media Findings, Database Cleanup, Backups, Cache, Logs, Cron, Autoload, File Types,
  Recovery Center, Settings) is server-rendered PHP — `WP_List_Table` subclasses where it's a list of
  findings, plain forms elsewhere — with one shared vanilla-JS helper (`storage-sherpa-admin.js`) providing
  generic `[data-ss-action]` buttons and bulk-action forms against the REST API. No JSX, no build step,
  directly testable by loading the page.
- **Charts are hand-rolled inline SVG/CSS** (`ss-donut`, `ss-trend` in the dashboard JS + CSS), not Chart.js —
  nothing here needed a charting library's interaction layer (tooltips, zoom, legends-with-toggle), so pulling
  in a real dependency for a pie + a sparkline wasn't worth it. Same reasoning passpress used for its
  plain-CSS peak-hour bar charts.
- **No real Tailwind build** — `assets/admin/css/storage-sherpa-admin.css` is a small hand-written utility/
  component set instead. The Tailwind Play CDN needs a live internet connection from wp-admin at runtime,
  which isn't a dependency worth adding for a handful of utility classes, and there's no Node build tooling
  wired into this plugin.

If a real npm/webpack pipeline becomes available later, migrating the dashboard from hand-written
`createElement` calls to JSX (and pulling in real Tailwind + Chart.js) is a drop-in upgrade — the REST API
underneath doesn't change either way.

## Naming conventions

| Thing | Convention | Example |
|---|---|---|
| Main plugin file | `storage-sherpa.php` | `Plugin Name: Storage Sherpa` |
| Constants | `STORAGE_SHERPA_` | `STORAGE_SHERPA_PLUGIN_DIR`, `STORAGE_SHERPA_DB_VERSION` |
| Functions / hooks | `storage_sherpa_` | `storage_sherpa_format_bytes()`, `do_action('storage_sherpa_daily_event')` |
| Classes | `SS_` | `SS_Trash`, `SS_Storage_Analyzer`, `SS_Database_Cleanup` |
| Module class files | `class-ss-*.php` | `class-ss-orphan-media-scanner.php` |
| Admin/inc "hub" files | `SS_Xxx.php` | `SS_Admin.php`, `SS_Cron.php` |
| Text domain | `storage-sherpa` | |
| DB table prefix | `{$wpdb->prefix}ss_` | `wp_ss_trash_items` |
| Capability | `manage_options` by default | filterable via `storage_sherpa_capability` |

## Directory structure

```
storage-sherpa/
├── storage-sherpa.php                  # ✅ Bootstrap: constants, activation hooks, module loader
├── uninstall.php                       # ✅ Drops plugin tables/options/trash dir (not on deactivate)
├── readme.txt                          # ✅
├── CLAUDE.md                           # ✅ this file
├── admin/
│   ├── SS_Admin.php                    # ✅ Menu registration, asset enqueue, wp.apiFetch nonce wiring
│   ├── SS_Dashboard.php                # ✅ Mount point for the wp.element dashboard app
│   ├── SS_Scan_Page.php                # ✅ Module 1/17 — category + largest-dirs + file-type breakdown
│   ├── SS_Media_Page.php               # ✅ Modules 2/3/4/7 — tabbed WP_List_Table, bulk trash
│   ├── SS_Database_Page.php            # ✅ Module 8 — category checklist + table maintenance
│   ├── SS_Tables_Page.php              # ✅ Module 9 — per-row backup-then-drop, no bulk action
│   ├── SS_Backups_Page.php             # ✅ Module 12
│   ├── SS_Cache_Page.php               # ✅ Module 13
│   ├── SS_Logs_Page.php                # ✅ Module 14
│   ├── SS_Cron_Page.php                # ✅ Module 15
│   ├── SS_Autoload_Page.php            # ✅ Module 16
│   ├── SS_Filetypes_Page.php           # ✅ Module 17
│   ├── SS_Recovery_Page.php            # ✅ Module 19
│   ├── index.php
│   └── settings/
│       ├── SS_Settings_Page.php        # ✅ General + Scheduled Scans (20) + Notifications (22) + Ignore Rules (23)
│       └── index.php
├── inc/
│   ├── SS_Functions.php                # ✅ format_bytes, dir_stats, path-safety checks, settings accessor
│   ├── SS_Install.php                  # ✅ dbDelta table creation, activation/deactivation, trash dir setup
│   ├── SS_Trash.php                    # ✅ Module 19 engine — see "Core safety mechanism" above
│   ├── SS_Cron.php                     # ✅ Module 20 — scheduled scans, auto-cleanup gate, retention sweep
│   ├── SS_Notifications.php            # ✅ Module 22
│   ├── SS_Ignore_Rules.php             # ✅ Module 23
│   ├── SS_Background_Process.php       # ✅ Module 24
│   ├── SS_REST_API.php                 # ✅ storage-sherpa/v1 — every route documented above
│   ├── index.php
│   └── modules/
│       ├── analyzer/class-ss-storage-analyzer.php        # ✅ Module 1
│       ├── media/
│       │   ├── class-ss-media-findings.php               # ✅ Shared table/CRUD for Modules 2/3/4/7
│       │   ├── class-ss-orphan-media-scanner.php          # ✅ Module 2
│       │   ├── class-ss-duplicate-finder.php              # ✅ Module 3
│       │   ├── class-ss-large-file-scanner.php            # ✅ Module 4
│       │   ├── class-ss-image-optimizer.php               # ✅ Module 5
│       │   └── class-ss-broken-media.php                  # ✅ Module 7
│       ├── filesystem/
│       │   ├── class-ss-empty-folder-cleaner.php          # ✅ Module 6
│       │   └── class-ss-filetype-analyzer.php             # ✅ Module 17
│       ├── database/
│       │   ├── class-ss-database-cleanup.php              # ✅ Module 8
│       │   ├── class-ss-orphan-tables.php                 # ✅ Module 9
│       │   └── class-ss-autoload-analyzer.php             # ✅ Module 16
│       ├── plugins/                    # ⏳ reserved — Module 10, not yet built
│       ├── themes/                     # ⏳ reserved — Module 11, not yet built
│       ├── backups/class-ss-backup-cleanup.php            # ✅ Module 12
│       ├── cache/class-ss-cache-cleaner.php                # ✅ Module 13
│       ├── logs/class-ss-log-cleaner.php                   # ✅ Module 14
│       ├── cron/class-ss-cron-manager.php                  # ✅ Module 15
│       ├── security/                   # ⏳ reserved — Module 18, not yet built
│       └── reports/                    # ⏳ reserved — Module 21, not yet built
├── assets/
│   └── admin/
│       ├── css/storage-sherpa-admin.css        # ✅ shared styles — see "Frontend" above
│       └── js/
│           ├── storage-sherpa-admin.js          # ✅ generic [data-ss-action]/bulk-action/scan-poll helpers
│           └── storage-sherpa-dashboard.js      # ✅ wp.element dashboard app
└── languages/                          # ⏳ no .pot generated yet
```

## Data model

**Custom DB tables** (all created via `dbDelta()` in `inc/SS_Install.php`, `register_activation_hook`):

- ✅ `{prefix}ss_scan_snapshots` — one row per category per scan (`scope`, `label`, `path`, `size_bytes`,
  `item_count`, `scanned_at`). Latest row per scope = today's dashboard numbers; grouped by date = the 30-day
  trend chart. No separate "history" table.
- ✅ `{prefix}ss_media_findings` — shared by Modules 2/3/4/7, disambiguated by `finding_type`. `group_hash` is
  only populated for duplicates (the shared file hash linking an `original` to its `duplicate`s).
- ✅ `{prefix}ss_trash_items` — Module 19. `item_type` is `file`, `db_row`, or `table_dump` — see
  [Core safety mechanism](#core-safety-mechanism-safe-trash-incss_trashphp) for what each stores and how
  restore differs per type.
- ✅ `{prefix}ss_cleanup_log` — every cleanup action across every module (`module`, `action`, `items_count`,
  `bytes_freed`, `run_by`, `run_type` [manual/cron], `created_at`). Reserved for Module 21 (Reports) to query
  once that module is built; nothing reads it yet beyond being written to.
- ✅ `{prefix}ss_ignore_rules` — Module 23. `rule_type` is one of folder/file/extension/table/image/plugin/theme.

**Options:**
- ✅ `storage_sherpa_settings` — the one settings blob (retention days, scan frequency, auto-cleanup category
  list, notification thresholds). `storage_sherpa_get_settings()` in `SS_Functions.php` is the single place
  defaults are defined — never read `get_option('storage_sherpa_settings')` directly elsewhere.
- ✅ `storage_sherpa_db_version`, `storage_sherpa_last_scan`.

**Filesystem:** `wp-content/storage-sherpa-trash/` — the physical Safe Trash for `file`-type entries, created
on activation with `index.php` + `.htaccess` deny-all protection.

## Not yet built

Deferred at the user's explicit request during this build, not because of any technical blocker. Each has a
reserved-but-empty directory under `inc/modules/` (see the directory tree above) and is **not** required by
`storage-sherpa.php` — adding it later means: create the class file(s), add one `require_once` in the
bootstrap, add its admin page + REST routes following the exact pattern every other module already
demonstrates.

- **Module 10 — Plugin Cleanup.** Detect leftover DB tables/options/cron jobs/upload folders/cache for
  plugins that are no longer active. This is also where the "orphan options" bullet from Module 8's spec
  properly belongs (see the Module 8 section above) — a curated per-plugin signature list, not a generic
  heuristic, since a wrong guess here risks deleting a live setting.
- **Module 11 — Theme Cleanup.** Unused themes, demo-import leftovers, XML/sample-content files, theme
  cache, screenshots.
- **Module 18 — Security Cleanup.** Temporary install files, old installer scripts, forgotten backup
  archives, unused maintenance files (`.maintenance`, leftover `wp-admin/upgrade` artifacts, etc.).
- **Module 21 — Reports.** PDF/CSV export, cleanup history view, storage trend report, recovered-storage
  summary. `{prefix}ss_cleanup_log` (written by every other module already) is exactly the data source this
  needs — building it is a read-only reporting layer over data that already exists, not new instrumentation.
  The Media Findings screen's "Export CSV" button was deliberately left out rather than wired to a
  non-existent handler — add it back here.
- **Module 25 — WP-CLI.** `wp storage-sherpa scan|cleanup|report|database|media|cache|cron`. Every module
  method this would call already exists and is REST-callable — CLI commands are a thin wrapper, same as the
  REST routes, not a second implementation.

## Verification status

Verified against the real `storagesherpa` WordPress install (DB `storagesherpa`, prefix `wp_`, MariaDB on
Windows/AMPPS, WP core reporting version 7.0.2) via a CLI bootstrap of `wp-load.php` — not just `php -l`
(all 62 files pass that too):

- **Activation**: `activate_plugin()` ran clean, `dbDelta()` created all 5 tables
  (`ss_scan_snapshots`, `ss_media_findings`, `ss_trash_items`, `ss_cleanup_log`, `ss_ignore_rules`).
- **All 13 admin screens** rendered as an authenticated administrator with zero fatal errors/warnings
  traceable to this plugin's code (`SS_Dashboard` through `SS_Settings_Page`).
- **All 18 REST GET routes** returned 200 via `rest_do_request()`, plus write-path routes exercised for
  real: add/list/remove an ignore rule, update settings, preview + run a Database Cleanup category (backed by
  a real `auto-draft` row, confirmed trashed then gone from `wp_posts`), OPTIMIZE/REPAIR/ANALYZE table
  maintenance, trash a real file → list Safe Trash → restore it → confirmed the file reappeared on disk,
  and a real Orphan Media Scanner run.
- **Module 24 (Background Scanner)**: a real job ran all 6 steps to `status: complete` via repeated
  `/scan/step` calls, matching how the dashboard's polling loop drives it.

**Two real bugs found and fixed while verifying** (both worth knowing before touching this code again):

1. **`information_schema.TABLES` returns its columns in UPPERCASE on this server regardless of how they're
   written in the query** (a documented MySQL/MariaDB quirk specific to `INFORMATION_SCHEMA`'s virtual
   tables — ordinary tables honor the case you type). `SS_Storage_Analyzer::scan_database()` and
   `SS_Orphan_Tables::scan()` originally selected `table_name, data_length, ...` and read back
   `$row->table_name` etc., which were silently `null` (PHP warnings, not fatals — `strtolower(null)` etc.).
   **Fixed** by adding explicit lowercase `AS` aliases to every `information_schema` column reference in both
   files. `SS_Database_Cleanup::total_table_bytes()` was never affected — it only ever used `get_var()`,
   which doesn't care about the result column's name.
2. **A job_id case-mismatch made `SS_Background_Process` progress get stuck forever at step 1.**
   `start_job()` and `process_step()`'s `set_transient()` calls used the raw (mixed-case)
   `$job_id`, while `get_status()` looked it up via `sanitize_key( $job_id )` (lowercased). MySQL's
   `utf8mb4_general_ci`-style collation matches `option_name` case-insensitively, so the *database row* was
   still found either way — but WordPress's non-persistent object cache keys are plain PHP strings compared
   case-*sensitively*, so `get_status()` kept hitting a stale cache entry from its first (lowercase-keyed)
   read and never saw the update `process_step()` had just written under the mixed-case key. Confirmed via a
   step-by-step reproduction: `process_step()`'s own return value correctly showed `current: 1`, but the very
   next `get_status()` call reported `current: 0` again — every call started over from the same stale
   snapshot. **Fixed** by normalizing the job_id (via `sanitize_key()`) exactly once, at generation time in
   `start_job()`, and reusing that single normalized value consistently in every transient-key call site
   instead of sanitizing (or not) ad hoc per call. Any future code building a cache/transient key from a
   generated random id should normalize once at the source, not at each read site.
3. **`settings_update()` used `$request->get_json_params()` directly** instead of `$request->get_param()`
   like every other route in `SS_REST_API.php` — `get_json_params()` returns `null` unless the request body
   was actually `Content-Type: application/json`, so a POST would silently save the *unchanged* defaults back
   over the real settings with no error. The dashboard's own `wp.apiFetch` calls happen to always send JSON
   (so this wasn't yet visible through the real UI), but it was inconsistent with the rest of the file and
   fragile. **Fixed** by reading each settings field through `$request->get_param()`, matching the pattern
   every other handler in this file already uses (`get_param()` transparently checks URL/query/body/JSON
   params in one call, regardless of how the client encoded the request).

**Still not verified against real data**, honestly:
- The Orphan Media Scanner's generic builder-heuristic pass (see Module 2 above) has no ACF/Elementor/Bricks/
  Oxygen/Beaver Builder/Meta Box/JetEngine install available in this environment — treat its `possibly_used`
  classification as genuinely provisional until tested against a site actually running one of those. The dev
  install currently has no attachments at all, so even the "definitive" sources (`used`) are unexercised
  against real content — only the code path (zero orphans, zero findings) was confirmed to run without error.
- AVIF/WebP encoding (Module 5) depends entirely on the server's GD/Imagick build — `avif_supported()`/
  `webp_supported()` haven't been called against this environment's actual PHP build to confirm which (if
  either) really works here.
- Cache Cleaner (Module 13) and Backup Cleanup (Module 12) integrations are written against each target
  plugin's *documented* public API/default path, but none of those six-plus plugins are installed on this
  site — `available_targets()` was confirmed to correctly return zero targets (i.e., detection doesn't
  false-positive), but no real purge/detect call against an actual WP Rocket/UpdraftPlus/etc. install has
  happened yet.
- Duplicate Finder, Large File Scanner, and Broken Media were exercised as empty-result code paths only (no
  attachments on the dev site) — the hashing/grouping/reconnect logic itself is unverified against real
  duplicate or broken files.
