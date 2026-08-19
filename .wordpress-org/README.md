# WordPress.org listing assets

These files are **not** part of the plugin. They belong in the `assets/`
directory of the plugin's SVN repository, which sits *beside* `trunk/`
rather than inside it:

    slug-sync/
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

Screenshots are still missing. They go here too, named `screenshot-1.png`,
`screenshot-2.png` and `screenshot-3.png`, and must match the order of the
captions under `== Screenshots ==` in `readme.txt`.
