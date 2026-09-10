# Changelog

Release notes for Avalanche Sync Guard. The version here, the `Version:` header
in `avalanche-sync-guard.php`, `const VERSION`, and the git tag must all match —
each site compares its installed header version against the latest release tag,
so a mismatch means the update is either never offered or offered and does
nothing.

## 1.3.0

- Sync Guard is now its own top-level admin section instead of an item under
  Tools, split into **Overview**, **Activity Log** and **Settings**.
- A red bubble appears on the menu when a destructive push has been recorded on
  a hosted environment in the last 30 days, so the warning is visible from any
  screen rather than only on Sync Guard's own pages.
- Logged times now carry their timezone (`7:35 pm GMT+0000` rather than a bare
  `7:35 pm`). A WordPress install whose timezone was never set reports UTC, and
  on these sites that is four hours ahead of the people reading the log — this
  makes that visible instead of quietly wrong.
- Self-updates from GitHub releases, so one repository feeds every site.
- Sites whose plugin folder is a **symlink** into the shared clone are never
  offered an update. Applying one would delete the symlink, write real files in
  its place, and silently detach that site from the repo it tracks — and those
  sites already run the newest code by definition.

## 1.2.0

- Backfill: reconstructs the activity log from content that already exists, so
  the log is not empty on a site that pre-dates the plugin. Entries are flagged
  as reconstructed rather than observed.
- Fixed a quadratic write in the backfill that exhausted PHP's memory limit — it
  re-read and rewrote the whole option once per post. Now built in memory and
  written once.

## 1.1.0

- Activity log beyond syncs: content published, unpublished, trashed, restored
  and deleted; custom post types and taxonomies registered for the first time;
  plugins activated and deactivated; theme switches; core, plugin and theme
  updates; `siteurl`, `home`, `permalink_structure` and `blogname` changes with
  before and after values; menu updates; new users and role grants.
- Post types are tracked cumulatively rather than as a snapshot diff, because
  plenty of them register conditionally — Beaver Builder's `fl_code` only exists
  on some requests, and a diff reported it appearing and vanishing forever.
- Anything new in the first 24 hours after activation is absorbed silently,
  while the plugin learns what normally exists on the site.

## 1.0.0

- Detects a database moving between environments and records it as a `PULL`,
  `PUSH` or `MOVE`, with how stale the arriving database was.
- Events are written to `wp_options` **and** to an append-only file under
  `wp-content/uploads`. A push replaces the database including any log stored in
  it, so the file copy is the one that survives the event most worth recording.
- Work-in-progress lock: a persistent admin warning, optionally a front-end
  banner, so clients don't edit content that is about to be overwritten.
