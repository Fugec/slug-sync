#!/usr/bin/env bash
#
# Builds the distributable plugin zip in build/, honouring .distignore.
# The zip contains a single slug-sync/ directory, which is what
# wordpress.org expects and what Plugins > Add New > Upload accepts.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="slug-sync"
version="$(sed -n 's/^ \* Version: *//p' "$root/$slug.php" | head -1 | tr -d '[:space:]')"

if [ -z "$version" ]; then
	echo "Could not read Version from $slug.php" >&2
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
echo
echo "Contents:"
unzip -Z1 "$zip" | sed 's/^/  /'
