# Slug Sync

Rewrites post, page, product and custom post type slugs to match their titles — with a preview
first, old URLs still redirecting, an exportable redirect map, and a full undo.

---

## Download

**[⬇ Download the latest release](https://github.com/Fugec/slug-sync/releases/latest/download/slug-sync.zip)**

Then in WordPress: **Plugins → Add New → Upload Plugin → Choose File → Install Now**.

Every release is also listed under [Releases](https://github.com/Fugec/slug-sync/releases) with a
version-stamped file (`slug-sync-1.0.0.zip`) if you need a specific one.

### ⚠️ Do not use the green “Code → Download ZIP” button

That button gives you `slug-sync-main.zip`, which unpacks to a folder called `slug-sync-main`.
WordPress treats a plugin's **folder name as its identity**, so that copy:

- registers under the wrong slug, breaking every translation string,
- will not receive updates as the same plugin,
- and includes development files that are not part of the plugin.

GitHub always names that archive `<repo>-<branch>` and it cannot be configured. It is a source
snapshot for developers, not an installable plugin. Use the release link above instead.

---

## Development

```bash
composer install     # PHPUnit 9.6, the only dev dependency
vendor/bin/phpunit   # unit tests
bash bin/build.sh    # → build/slug-sync-<version>.zip
```

`bin/build.sh` is what produces a real plugin zip: it filters the tree through `.distignore` and
stages it into a folder named `slug-sync`, which is the plugin slug. It refuses to build if the
version in the plugin header, `SLUG_SYNC_VERSION` and the readme's `Stable tag` disagree.

Pushing a `v*` tag runs [`.github/workflows/release.yml`](.github/workflows/release.yml), which
checks the tag against the plugin header, builds, and publishes both the versioned and the
stable-named zip as release assets.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
