=== Slug Sync ===
Contributors: arminkapetanovic
Tags: slug, permalink, transliteration, woocommerce, redirect
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview and safely regenerate content slugs, with transliteration, WooCommerce SKU cleanup, redirects, reports and undo.

== Description ==

Imported products and posts often end up with slugs that have nothing to do with their titles. Fixing them by hand through Quick Edit is not realistic once you are past a few dozen.

Slug Sync regenerates slugs in bulk from titles, exactly as WordPress would if each post were saved with an empty slug. Optional Free controls can first transliterate the title, leave its exact assigned WooCommerce SKU out of the URL, or add that SKU from product data. The rewriting is the easy part. The point of the plugin is doing it on a live site without breaking anything.

**Build cleaner slugs without changing titles**

Readable Latin characters are an optional part of every run. Cyrillic and Greek are transliterated locally by a map bundled with the plugin, so the same title produces the same slug on every supported host rather than one address on a server with PHP's international text support and a different one without it. Greek follows ELOT 743, the standard behind Greek passports and road signs, which reads vowel pairs as pairs: `Ναύπλιο` becomes `nafplio`, not `nayplio`. Where the server does provide international text support, it extends coverage to further scripts such as Chinese without altering the Cyrillic or Greek result. Nothing is sent to a translation service, and this is transliteration rather than a translation into English.

For WooCommerce products, a second option controls how the exact SKU assigned to that product affects its slug. Leave the SKU out when it already appears in the title, or deliberately add it from Product data → Inventory when it does not. Slug Sync does not guess at model numbers, remove unrelated codes or add the same SKU twice, and it leaves a name alone when removal would make the result unclear. The visible product title and stored SKU never change.

Both choices use the same Preview, report and Undo protections as an ordinary title-based run. The changes report notes which transformation produced each proposed slug, and returning from a preview restores the same choices for Apply.

**Check the stack and measure the change**

A read-only compatibility preflight names active permalink, translation, redirect, SEO and caching plugins that may also affect public URLs. It is a focused warning based on the active plugin list, not a promise that custom code or the whole server stack is compatible.

Every completed Preview includes a URL change summary measured from that run: public URLs changed, redirects WordPress can keep automatically, redirect rows that need importing, and targets adjusted to avoid conflicts. These are operational counts, not a guessed SEO score or a ranking forecast.

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

Slug Sync is complete on its own and has no limits. Slug Sync Pro is a separate add-on, built and in final release checks at slugsync.com. It can remove unwanted words and unassigned product codes, build product URLs from WooCommerce fields, and extend transliteration to those fields and to term names; add chosen categories, tags and reusable attribute values from product names without changing those names; and safely update category, tag and product-attribute URLs with direct redirects, cache clearing and a seven-day 404 watch. It is not required for anything described above, and nothing described above will ever move into it.

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

= Does transliteration translate my titles into English? =

No. It writes the same sounds with readable Latin characters, such as `Кофеварка` to `kofevarka`. It does not change the visible title, infer another language, or contact an external translation service. Cyrillic and Greek come from a map bundled with the plugin; additional scripts are available when the server provides PHP's international transliterator. Always review the preview for the language used on your site.

= Will the same title give the same slug on every server? =

For Cyrillic and Greek, yes. Those two scripts are resolved by the bundled map before PHP's international transliterator is consulted, so a site moved between hosts does not regenerate different URLs for the same titles. Greek follows ELOT 743 and reads vowel pairs as pairs, so `Φίλτρου` becomes `filtrou` rather than `filtroy`, and `Λευκάδα` becomes `lefkada`. Scripts outside the bundled map, such as Chinese, are handled only where the server provides the international transliterator, so those do depend on the host.

= How can Free use a WooCommerce SKU in the URL? =

Free can leave out the exact WooCommerce SKU saved on that product when the same value appears as a separate part of its title, or add the assigned SKU from Product data → Inventory when it is absent. Other model numbers and codes remain, and the same SKU is never added twice. If removing it would leave fewer than two useful name words, Slug Sync keeps the original title source instead. Pro adds broader configurable code, filler-word and replacement rules.

= Can I undo a run? =

Yes. Previous runs provide an Undo changes button for each applied run. Any item whose slug changed again after that run is skipped instead of being overwritten, as is any item whose old slug has since been claimed elsewhere.

= Is it safe on a large site? =

It processes in batches and continues automatically, and there is an option to stop after the first batch so you can inspect the result before committing to a full run. Take a database backup first regardless.

== Screenshots ==

1. The guided setup screen on a first visit: content type, how the new slugs are built, Preview or Apply, how each change is saved, and an optional Bonus step for what is included.
2. Every step opened at once, including the Bonus step, which says outright that nothing inside it has to be picked before a run.
3. The steps with choices made: readable Latin characters, how an assigned WooCommerce SKU is treated, Preview rather than Apply, and a pause after the first batch.
4. The preview running over the screen: percentage progress, the total found so far, the newest three matches, and one Slug Sync or Pro feature at a time to read while the batches continue.
5. The same run paused after its first batch, with the partial reports already written and downloadable, and Resume or Stop offered without leaving the screen.
6. The finished preview, ending on its two named reports: the changes CSV to review, and the redirect map to keep and import.
7. That preview carried back to the setup screen: measured counts rather than an invented SEO score, the choices restored ready for Apply, and the Pro workflows standing where the introduction was.
8. The redirect report opened in Google Sheets: relative source and target pairs with no header row, ready to import into a redirect plugin.
9. The changes report opened in Google Sheets: every item with its title, old and new slug, old and new URL, and a note column flagging duplicate titles.

== Changelog ==

= 1.0.0 =
* Initial release.
* Guided Preview, Review and Apply workflow with plain-language explanations for every write method and scope option.
* Optional offline transliteration produces readable Latin slugs from Cyrillic and Greek titles, identically on every host, with Greek following ELOT 743 and broader script coverage where PHP intl is available.
* Optional WooCommerce cleanup can leave a product's exact assigned SKU out of its slug or add it from product data, without changing the product title or stored SKU.
* Completed previews restore their content type and slug-building choices so Apply matches what was reviewed.
* A read-only compatibility preflight names active permalink, translation, redirect, SEO and caching plugins that may also affect public URLs.
* Completed previews show measured URL-change, automatic-redirect, redirect-import and conflict-adjustment counts rather than a guessed SEO score.
* Running, paused and completed batches use a focused progress dialog with live matches, report downloads and the correct next action for each state.
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
