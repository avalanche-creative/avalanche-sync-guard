# Avalanche Sync Guard

Builds a history of what happened to this WordPress site, from inside WordPress.

These sites are not under version control, so when two environments disagree there
is nothing to read to find out why. This plugin records the two things that answer
that question: **every time the database moved between environments**, and **every
change big enough that you'd want to know which environment it happened in**.

It also has a **work-in-progress lock** — a toggle that warns anyone editing the
site that their changes are about to be overwritten.

---

## 1. Sync tracking (push / pull)

Local Connect is a desktop app; a plugin cannot hook into it. So this plugin
detects the *effect* of a sync instead.

It keeps a stamp inside the database recording which environment that database was
last running in, and compares it against the environment it is actually running in
now. When those disagree, a database has moved — and the direction says what
happened:

| Stamp says | Now running in | Logged as | Meaning |
|---|---|---|---|
| WP Engine | Local | `PULL` | Production came down to Local. The safe direction. |
| Local | WP Engine | `PUSH` | A local database went up over production. **Destructive.** |
| WP Engine A | WP Engine B | `MOVE` | Staging ↔ production copy. |

Each event records both environments and **how stale the arriving database was** —
so after a push you can say "the database that landed on production had been
sitting on someone's laptop for six days" without guessing.

If a `PUSH` was recorded on a hosted environment in the last 30 days,
administrators see a red notice naming the date.

---

## 2. Activity log

Everything below is recorded with a timestamp, the user who did it, and which
environment it happened in.

**Content**

- Pages, posts and custom-post-type entries created, published, unpublished,
  trashed, restored, and permanently deleted
- Beaver Builder layouts published (`fl_builder_after_save_layout`) — on these
  sites that's the real "this page got built" signal

**Structure**

- A custom post type or taxonomy registered for the first time — caught however it
  was made: ACF, CPT UI, or hand-written in `functions.php`
- Plugins activated and deactivated
- Theme switched
- Core, plugin and theme updates
- `siteurl`, `home`, `permalink_structure` and `blogname` changes, with before and
  after values — these are the ones that silently break a site after a migration
- Navigation menus updated
- New user accounts, and anyone granted administrator or editor

**Deliberately not logged**, because it's machinery rather than work: revisions,
autosaves, attachments, nav-menu-item rows, Beaver Builder history rows, and
individual ACF field records. Field *groups* are logged; the fields inside them are
not. Adjust with the `asg_ignored_post_types` filter.

### Why post types are tracked cumulatively

Plenty of post types register conditionally — Beaver Builder's `fl_code` only
exists on some requests. A snapshot diff would report it appearing and vanishing
forever, so the plugin keeps an "ever seen" record instead and reports each type
exactly once, the first time it is ever seen.

For the same reason, anything that turns up in the **first 24 hours after
activation** is absorbed silently. That's the settling-in period while the plugin
learns what normally exists here. Genuinely new types logged after that are real.

Types *going away* aren't logged, because the cause is always something already
recorded with a clearer label — a plugin deactivated, or an ACF post-type record
deleted.

---

## 3. The work-in-progress lock

**Sync Guard → Settings.** Turn it on in the environment you want the warning to
appear in — normally **production**, while you rebuild locally.

- **Admin screens only** *(default)* — a persistent, non-dismissible warning on every
  wp-admin page. Nothing is visible to the public. This is almost always the one
  you want: the audience for "don't make changes" is whoever is editing content.
- **Admin + front-end banner for logged-in users**
- **Admin + front-end banner for everyone** — the public sees it on a live site.
  Only for a genuine planned outage.

Switching the lock on or off is itself logged, with who did it.

---

## Where the log lives

Written to two places, on purpose:

1. **`wp_options`** — fast to read. Sync events live in `asg_events` and everything
   else in `asg_activity`, kept separate so a flood of content events can never
   push the sync history out of the window.
2. **`wp-content/uploads/avalanche-sync-guard/events.log`** — append-only
   JSON-lines. A database push replaces the database *including any log stored in
   it*, so the file copy is the one that survives the event most worth recording.
   Rotates at 2 MB, one generation kept. The directory is protected with an
   `.htaccess` deny rule and an `index.php`.

The admin screen reads the file first and falls back to the database. **Download**
on the Overview page gives you the raw file, so you can pull one from Local and one
from production and compare where the two diverged.

---

## Where it lives in the admin

Sync Guard is its own top-level menu, sitting directly under Dashboard rather than
inside Tools — it answers "did the database move?" and "is this site safe to edit
right now?", which are questions you ask *before* touching anything, not a utility
you go looking for.

| Page | What's on it |
|---|---|
| **Overview** | Which environment this is, when the database was last stamped, the newest content edit, and the full sync history. Download the raw log here. |
| **Activity Log** | Content and structural changes, filterable by kind, plus the backfill button. |
| **Settings** | The work-in-progress lock: on/off, the notice text, and who sees it. |

When a destructive push has been recorded on a hosted environment in the last 30
days, the menu itself carries a red bubble, so the warning is visible from any
screen in the admin rather than only on Sync Guard's own pages.

### Known limitation

Detection runs on `admin_init`, so a sync is recorded the first time someone loads
a wp-admin page afterwards. Direction and both environments are always correct;
only the timestamp can lag. This was deliberate — checking on every front-end
request would add a query to every page view on a live site.

---

## One repo, sixteen sites

This plugin lives in exactly one place:

    ~/Local Sites/_shared/avalanche-sync-guard   ← the git repo, edit here

Nothing in it is site-specific — environment detection uses the `PWP_NAME`
constant on WP Engine and the `*.local` host on Local, falling back to the
hostname anywhere else — so the same code runs everywhere.

### Local sites: symlink

Each local site points at the shared clone rather than holding its own copy:

    ln -s ~/Local\ Sites/_shared/avalanche-sync-guard \
          ~/Local\ Sites/<site>/app/public/wp-content/plugins/avalanche-sync-guard

Edit the repo, every local site sees it immediately. Each site still keeps its
own log and settings — those live in that site's database and uploads folder,
not in the plugin.

Do **not** clone the repo separately into a second site. That is the trap
`CLAUDE.md` describes: whichever copy commits first wins and the other quietly
becomes a stale fork.

### Production: GitHub releases

A symlink cannot reach production — git stores the link, not the files, so a
WP Engine deploy would land a broken plugin. Instead the plugin updates itself
from GitHub releases (see `inc/class-asg-updater.php`).

Install the zip once on each production site. From then on, tagging a release
makes every site show "Update available" under Plugins, updating with one click
like any plugin from wordpress.org. Sites check every six hours; the plugin row
also carries a **Check for updates** link that clears the cache immediately.

### Cutting a release

    cd ~/Local\ Sites/_shared/avalanche-sync-guard
    # bump Version: in the plugin header AND const VERSION, keep them identical
    git commit -am "1.4.0 — what changed"
    git tag v1.4.0
    git push && git push --tags
    gh release create v1.4.0 --title "1.4.0" --notes "What changed"

The version in the header is what each site compares against the release tag, so
a release whose tag is not higher than the installed version simply will not be
offered. The updater strips a leading `v`, so `v1.4.0` and `1.4.0` both work.

## Two things to know when it lands on a new site

1. Its first run logs a `baseline`, not a `push`. It can only compare against
   stamps it wrote itself, so real detection starts from that point.
2. Install it on **both** Local and production. The Local copy writes the stamp
   that the production copy later reads to recognise a push.
