#!/usr/bin/env bash
# Build the release zip of the plugin.
#
# Usage: bin/build-zip.sh [OUT_DIR]   (default: dist)
#
# Writes OUT_DIR/genready/ (the plugin folder, for Plugin Check) and
# OUT_DIR/genready-<version>.zip with its .sha256 file. The zip has one top
# folder, "genready", so WordPress installs it under the right slug.
# Fails when the three version strings differ, when the readme has no
# changelog entry for the version, or when a development file is in the copy.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/dist}"

header=$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$root/genready.php")
constant=$(sed -n "s/^define( 'GENREADY_VERSION', '\([^']*\)' );.*/\1/p" "$root/genready.php")
stable=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$root/readme.txt")

if ! [[ "$header" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "genready.php has no valid Version header: '$header'" >&2
	exit 1
fi
if [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Versions differ: header $header, GENREADY_VERSION $constant, Stable tag $stable" >&2
	exit 1
fi
if ! grep -qx "= $header =" "$root/readme.txt"; then
	echo "readme.txt has no changelog entry '= $header ='" >&2
	exit 1
fi

rm -rf "$out/genready" "$out/genready-$header.zip" "$out/genready-$header.zip.sha256"
mkdir -p "$out/genready"
rsync -a --exclude-from="$root/.distignore" "$root/" "$out/genready/"

dev=$(cd "$out/genready" && find . \( -name '.git*' -o -name '.github' -o -name '.distignore' \
	-o -name 'tests' -o -name 'e2e' -o -name 'bin' -o -name 'vendor' -o -name 'node_modules' \
	-o -name 'composer.*' -o -name '*.dist' -o -name '.superpowers' -o -name '.worktrees' -o -name 'dist' \) -print)
if [ -n "$dev" ]; then
	echo "Development files in the release copy:" >&2
	echo "$dev" >&2
	exit 1
fi

if command -v zip >/dev/null; then
	(cd "$out" && zip -qrX "genready-$header.zip" genready)
else
	(cd "$out" && python3 -I -m zipfile -c "genready-$header.zip" genready)
fi
(cd "$out" && sha256sum "genready-$header.zip" > "genready-$header.zip.sha256")

echo "version=$header"
echo "zip=$out/genready-$header.zip"
