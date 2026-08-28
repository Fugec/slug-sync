# Slug Sync

Safely regenerates post, page, product and custom post type slugs from their titles, with optional
offline transliteration and exact WooCommerce SKU cleanup in either direction, a compatibility
preflight, measured Preview summary, old URLs still redirecting, an exportable redirect map, and a
full undo.

Cyrillic and Greek transliteration is resolved by a map bundled with the plugin, so the same title
produces the same slug on every host; Greek follows ELOT 743, including its vowel pairs. PHP's
international text support, where the server has it, adds scripts the map does not cover without
changing that result.

---

## Download

**[⬇ Download Slug Sync](https://github.com/Fugec/slug-sync/releases/download/dev/slug-sync.zip)**

Then in WordPress: **Plugins → Add New → Upload Plugin → Choose File → Install Now**.

That link is rebuilt from `main` on every push, so it always carries the latest changes. Fixed,
version-stamped builds (`slug-sync-1.0.0.zip`) are listed under
[Releases](https://github.com/Fugec/slug-sync/releases) if you need to pin one.

### ⚠️ Do not use the green “Code → Download ZIP” button

That button gives you `slug-sync-main.zip`, which unpacks to a folder called `slug-sync-main`.
WordPress treats a plugin's **folder name as its identity**, so that copy:

- registers under the wrong slug, breaking every translation string,
- will not receive updates as the same plugin,
- and includes development files that are not part of the plugin.

GitHub always names that archive `<repo>-<branch>` and it cannot be configured. It is a source
snapshot for developers, not an installable plugin. Use the download link above instead.

---

## Development

```bash
composer install     # PHPUnit 9.6, the only dev dependency
vendor/bin/phpunit   # unit and regression tests
bash bin/build.sh    # → build/slug-sync-<version>.zip
```

`bin/build.sh` is what produces a real plugin zip: it filters the tree through `.distignore` and
stages it into a folder named `slug-sync`, which is the plugin slug. It refuses to build if the
version in the plugin header, `SLUG_SYNC_VERSION` and the readme's `Stable tag` disagree.

Both publishing workflows first run the test suite and PHP syntax checks on PHP 7.4 and 8.4.
Every push to `main` runs
[`dev-build.yml`](.github/workflows/dev-build.yml), which rebuilds it and republishes the `dev`
prerelease behind the download link above. Pushing a `v*` tag runs
[`release.yml`](.github/workflows/release.yml), which checks the tag against the plugin header,
builds, and publishes both the versioned and the stable-named zip as a real release.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
