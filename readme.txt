=== Slug Sync ===
Contributors: arminkapetanovic
Tags: slug, permalink, seo, woocommerce, redirect
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: Armin Kapetanovic

Rewrite post, page and product slugs to match their titles, without breaking the URLs you already have indexed.

== Description ==

Imported products and posts often end up with slugs that have nothing to do with their titles. Fixing them by hand through Quick Edit is not realistic once you are past a few dozen.

Slug Sync rewrites slugs in bulk to match titles, exactly as WordPress would if each post were saved with an empty slug. The rewriting is the easy part. The point of the plugin is doing it on a live site without breaking anything.

**Preview before anything is written**

The preview writes no changes and produces a CSV of every slug it would alter, with the old and new URL side by side.

Previews are collision accurate. If two posts share a title, one of them has to take a numeric suffix. A naive preview cannot see slugs claimed earlier in the same run, so it shows both posts taking the same slug, and the redirect map ends up pointing at a URL that will never exist. Slug Sync tracks claims across the whole run, so the preview matches what applying will actually produce, and every suffixed post is flagged in a note column.

**Old URLs keep working**

WordPress records a post's previous slug and quietly 301s the old URL to the new one. A direct database write would skip that, so the plugin writes the old-slug record itself. Your existing links keep resolving whether or not you use a redirect plugin.

On top of that, every applied change is written to a second CSV of relative source and target pairs, ready to import into a redirect plugin as permanent redirects. It has no header row, because some importers treat a header as a live redirect, and it covers published posts only, because draft permalinks are query strings rather than real URLs.

**Quiet writes**

By default slugs go straight to the posts table, skipping `save_post`. On a store with thousands of products that avoids firing webhooks and integration syncs once per product, and leaves `post_modified` untouched, so your entire catalogue does not get a fresh sitemap lastmod on the same day. A normal update that fires all hooks is available if something in your stack needs it. Yoast indexables are cleared for changed posts either way, so canonicals do not go stale.

**Undo changes**

Every run gets its own timestamped history entry and reports. An applied run's report doubles as an undo: it restores the slugs recorded for that specific run. Anything edited since the run is skipped instead of being overwritten.

**Resume safely**

Batch progress is stored by WordPress rather than only in the browser. If a tab closes or a request is interrupted, the unfinished run can resume from its last completed batch. A run lock prevents overlapping tabs or administrators from processing the same catalogue at once.

**Other details**

* Works on any public post type, not just WooCommerce products.
* Batched with keyset pagination, so nothing is skipped if someone publishes while a run is in progress, and long runs do not time out.
* Keeps separate reports and undo controls for each run.
* Resumes interrupted runs and prevents overlapping runs.
* Slugs are capped at a word boundary before they can overflow the database column.
* Reports are written to a directory with a random name, so your content is not enumerable.
* Restricted to administrators.
* No telemetry, no external requests, no upsells.

== Installation ==

1. Upload the plugin through Plugins, Add New, Upload Plugin, or install it from the plugin directory.
2. Activate it.
3. Go to Tools, Slug Sync.

== Frequently Asked Questions ==

= Will this break my URLs? =

Old URLs keep redirecting, because the plugin records each previous slug the way WordPress does. The exported redirect map is there as a second layer, and for anything you would rather handle in a dedicated redirect plugin. Purge your page cache and CDN after a run, and resubmit your sitemap.

= What happens to two posts with the same title? =

The lower post ID keeps the clean slug and the other takes a numeric suffix, which is what WordPress does normally. Both are flagged in the note column of the report, so you can rename them properly if you would rather not have a suffix.

= Does it touch product variations? =

No. Variations are a separate post type and are never listed.

= Can I undo a run? =

Yes. Previous runs provides an Undo changes button for each applied run. Anything edited since that run is skipped instead of being overwritten.

= Is it safe on a large site? =

It processes in batches and continues automatically, and there is an option to stop after the first batch so you can inspect the result before committing to a full run. Take a database backup first regardless.

== Screenshots ==

1. The guided setup screen with plain-language Preview, Apply, write method and scope choices.
2. A preview run listing the slugs that would change.
3. Previous runs with report downloads, resume controls and Undo changes actions.

== Changelog ==

= 1.0.0 =
* Initial release.
* Guided Preview, Review and Apply workflow with plain-language explanations for every write method and scope option.
* Collision-accurate previews that track slug claims across the whole run.
* Old URLs keep redirecting, plus an exportable redirect map for published posts.
* Quiet writes that skip `save_post` hooks, or a full update that fires them.
* Persistent run history with per-run change and redirect reports, and per-run undo.
* Resume and cancel controls for interrupted runs, with an atomic lock preventing overlapping runs.
