=== Slug Sync ===
Contributors: arminkapetanovic
Tags: slug, permalink, seo, woocommerce, redirect
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rewrite post, page and product slugs to match their titles, without breaking the URLs you already have indexed.

== Description ==

Imported products and posts often end up with slugs that have nothing to do with their titles. Fixing them by hand through Quick Edit is not realistic once you are past a few dozen.

Slug Sync rewrites slugs in bulk to match titles, exactly as WordPress would if each post were saved with an empty slug. The rewriting is the easy part. The point of the plugin is doing it on a live site without breaking anything.

**Preview before anything is written**

The preview writes no changes and produces a CSV of every slug it would alter, with the old and new URL side by side.

Previews are collision accurate. If two posts share a title, one of them may have to take a numeric suffix. A naive preview cannot see slugs occupied or released earlier in the same run, so its redirect map can point at a URL that will never exist. Slug Sync simulates WordPress's real rules across the whole run: flat post types share one namespace, hierarchical slugs are scoped to their parent, drafts and pending items keep WordPress's no-uniqueness behaviour, and existing numeric suffixes increment normally. Every suffixed post is flagged in a note column.

**Old URLs keep working**

WordPress records a post's previous slug and quietly 301s the old URL to the new one. A direct database write would skip that, so the plugin writes the old-slug record itself. Your existing links keep resolving whether or not you use a redirect plugin.

That built-in redirect covers content that does not nest inside a parent: posts, products and most custom post types. WordPress does not record old slugs for pages, whatever tool changes them, so pages need the redirect report below. See the FAQ before running this on pages.

On top of that, every applied change is written to a second CSV of relative source and target pairs, ready to import into a redirect plugin as permanent redirects. It has no header row, because some importers treat a header as a live redirect, and it covers published posts only, because draft permalinks are query strings rather than real URLs.

**Quiet writes**

By default slugs go straight to the posts table, skipping `save_post`. On a store with thousands of products that avoids firing webhooks and integration syncs once per product, and leaves `post_modified` untouched, so your entire catalogue does not get a fresh sitemap lastmod on the same day. A normal update that fires all hooks is available if something in your stack needs it. Yoast indexables are cleared for changed posts on the quiet path, so canonicals do not go stale; a standard update leaves that to Yoast's own save hooks.

**Undo changes**

Every run gets its own timestamped history entry and reports. An applied run's report doubles as an undo: it restores the slugs recorded for that specific run. Any item whose slug changed again after the run is skipped instead of being overwritten. If another item has claimed an old slug in the meantime, Undo skips that conflict rather than creating two posts with the same slug.

**Resume safely**

Batch progress is stored by WordPress rather than only in the browser. Before Apply changes an item, a private write-ahead journal is flushed to uploads; the public changes row then becomes that item's commit marker. If a tab closes, a hook fails, or a request is interrupted before the batch checkpoint is saved, Resume reconciles those rows without truncating or duplicating the Undo report. A run lock prevents overlapping tabs or administrators from processing the same catalogue at once.

**Other details**

* Works on any public post type, not just WooCommerce products.
* Batched with keyset pagination, avoiding the duplicate/skip drift caused by SQL OFFSET when items are added or removed during a run.
* Keeps separate reports and undo controls for each run, pruning the oldest once fifty runs have accumulated so old reports do not pile up in uploads.
* Shows a rolling live feed of the thirty most recent matches during a run, while the downloadable CSV retains the complete result.
* Resumes interrupted runs and prevents overlapping runs.
* Slugs are capped at a word boundary before they can overflow the database column.
* Reports are written to a directory with a random name, so your content is not enumerable.
* Restricted to administrators.
* No telemetry and no external requests. The plugin never contacts a server.
* Everything above is uncapped: no item limit, no trial, nothing expires.

**About Slug Sync Pro**

Slug Sync is complete on its own and has no limits. Slug Sync Pro is a separate add-on, built and in final release checks at slugsync.com. It can remove unwanted name text, transliterate non-Latin words and build product URLs from WooCommerce fields; add chosen categories, tags and reusable attribute values from product names without changing those names; and safely update category, tag and product-attribute URLs with direct redirects, cache clearing and a seven-day 404 watch. It is not required for anything described above, and nothing described above will ever move into it.

== Installation ==

1. Upload the plugin through Plugins, Add New, Upload Plugin, or install it from the plugin directory.
2. Activate it.
3. Go to Tools, Slug Sync.

== Frequently Asked Questions ==

= Will this break my URLs? =

For posts, products and other content that does not nest inside a parent, old URLs keep redirecting, because the plugin records each previous slug the way WordPress does. The exported redirect map is there as a second layer, and for anything you would rather handle in a dedicated redirect plugin. Pages are the exception and have their own answer below. Purge your page cache and CDN after a run; sitemaps need no resubmission, since Google retired its ping endpoint in 2023.

= What happens to two posts with the same title? =

For posts in the same WordPress slug namespace, the lower post ID is processed first and keeps the clean available slug; the other takes the next numeric suffix. Pages under different parents may both keep the same final segment because WordPress treats those as different namespaces. Every suffix added by WordPress is flagged in the report.

= Can I run this on pages? =

Yes, but read this first, because pages behave differently from posts and products. WordPress only records a previous slug for content that does not nest inside a parent, so it will not redirect an old page URL by itself. Import the redirect report into a redirect plugin instead.

There is a second consequence. A page's URL contains its parents' slugs, so renaming a parent also changes the URL of every page beneath it. Those child URLs are not in the reports, which list only the items whose own slug changed, and for the same reason a preview of a child shows the parent's current slug rather than its new one. Check what sits under anything you rename. Posts, products and other non-nesting types are unaffected by either point, and the plugin says all of this on screen when you select a nesting type.

= Does it touch product variations? =

No. Variations are a separate post type and are never listed.

= Can I undo a run? =

Yes. Previous runs provides an Undo changes button for each applied run. Any item whose slug changed again after that run is skipped instead of being overwritten, as is any item whose old slug has since been claimed elsewhere.

= Is it safe on a large site? =

It processes in batches and continues automatically, and there is an option to stop after the first batch so you can inspect the result before committing to a full run. Take a database backup first regardless.

== Screenshots ==

1. The guided setup screen: content type, Preview or Apply, how each slug is saved, and what is included, each with a plain-language explanation.
2. A completed product preview carried back above Step 1, with the contextual Pro workflow carousel, setup form and run history still visible below.
3. A running preview with percentage progress, the total found, a rolling list that marks the newest batch, and per-run report downloads below.
4. A completed product preview with recent matches, the later Pro URL-safety cards, report downloads and the full run history.
5. The first four Pro workflow cards, including one-row scrollable URL examples, above the latest highlighted run and its report buttons.
6. The redirect report opened in Google Sheets: relative source and target pairs with no header row, ready to import into a redirect plugin.
7. The changes report opened in Google Sheets: every item with its title, old and new slug, old and new URL, and a note column flagging duplicate titles.

== Changelog ==

= 1.0.0 =
* Initial release.
* Guided Preview, Review and Apply workflow with plain-language explanations for every write method and scope option.
* Collision-accurate previews that track slug claims across the whole run.
* Old URLs keep redirecting, plus an exportable redirect map for published posts.
* Quiet writes that skip `save_post` hooks, or a full update that fires them.
* Persistent run history with per-run change and redirect reports, and per-run undo, capped at fifty runs.
* Resume and cancel controls for interrupted runs, with an atomic lock preventing overlapping runs.
* A flushed write-ahead journal makes interrupted Apply batches recoverable without losing Undo rows.
* Undo refuses to recreate a slug that another post has claimed since the run.
* Preview reports how many titles contain product codes, filler words or non-Latin script.
* Running previews show recent matches immediately and mark the newest batch without rendering an unbounded result list.
* The `slug_sync_source_title` filter lets add-on plugins rewrite a title before its slug is generated.
