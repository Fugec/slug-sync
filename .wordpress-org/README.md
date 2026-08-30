# WordPress.org listing assets

These files are **not** part of the plugin. They belong in the `assets/`
directory of the plugin's SVN repository, which sits *beside* `trunk/`
rather than inside it:

    slugsync/
      assets/     <- the contents of this directory
      tags/
      trunk/      <- the plugin itself

`.distignore` keeps this directory out of the distributed zip.

| File | Purpose |
| --- | --- |
| `banner-772x250.png` | Listing header, standard resolution. Required. |
| `banner-1544x500.png` | Listing header, high resolution. |
| `icon-128x128.gif` | Plugin icon, standard resolution. Animated. |
| `icon-256x256.gif` | Plugin icon, high resolution. Animated. |
| `screenshot-1.png` … `screenshot-18.png` | Current SlugSync workflow, real multilingual WooCommerce catalog and Preview evidence. |

Only one raster format is supplied per icon size, which is what the handbook
expects; a PNG fallback is required only alongside `icon.svg`, and there is no
SVG here. Both icons are well under the 1 MB per-icon limit.

The screenshot order must keep matching the captions under `== Screenshots ==`
in `readme.txt`.
