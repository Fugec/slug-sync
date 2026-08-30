#!/usr/bin/env bash
#
# Builds the distributable plugin zip in build/, honouring .distignore.
# The zip contains a single slugsync/ directory, which is what
# wordpress.org expects and what Plugins > Add New > Upload accepts.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="slugsync"
plugin_file="slugsync.php"
version="$(sed -n 's/^ \* Version: *//p' "$root/$plugin_file" | head -1 | tr -d '[:space:]')"

if [ -z "$version" ]; then
	echo "Could not read Version from $plugin_file" >&2
	exit 1
fi

# The three places a version is written have to agree. WordPress reads the
# plugin header, wordpress.org reads Stable tag, and the plugin reads its own
# constant; if they diverge the directory serves one version and installs
# another, which is not something users can fix themselves.
constant="$(sed -n "s/^define( 'SLUG_SYNC_VERSION', '\([^']*\)' );.*/\1/p" "$root/$plugin_file" | head -1)"
stable="$(sed -n 's/^Stable tag: *//p' "$root/readme.txt" | head -1 | tr -d '[:space:]')"

if [ "$constant" != "$version" ] || [ "$stable" != "$version" ]; then
	echo "Version mismatch -- refusing to build:" >&2
	echo "  $plugin_file header:   $version" >&2
	echo "  SLUG_SYNC_VERSION:     $constant" >&2
	echo "  readme.txt Stable tag: $stable" >&2
	exit 1
fi

stage="$root/build/$slug"
zip="$root/build/$slug-$version.zip"

rm -rf "$root/build"
mkdir -p "$stage"

rsync -a --exclude-from="$root/.distignore" --exclude='/build' --exclude='.DS_Store' \
	"$root/" "$stage/"

(cd "$root/build" && zip -qr "$zip" "$slug")

echo "Built $zip"
echo "  file name:   $slug-$version.zip   (changes with every release)"
echo "  inner folder: $slug/               (must never change -- it is the plugin slug)"
echo
echo "Contents:"
unzip -Z1 "$zip" | sed 's/^/  /'
