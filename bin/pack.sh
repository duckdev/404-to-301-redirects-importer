#!/usr/bin/env bash
#
# Build a release ZIP of the 404 to 301 - Redirects Importer addon.
#
# Steps:
#   1. Verify Version: header / package.json match.
#   2. Generate the POT file.
#   3. Build the front-end asset.
#   4. Stage only runtime files and zip them into ./releases/.
#
# Intentionally lighter than the parent's pack.sh: the addon is a
# single PHP file with one JS bundle — no composer deps shipped,
# no vendor/ to install.

set -euo pipefail

SLUG="404-to-301-redirects-importer"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT/404-to-301-redirects-importer.php"
PKG_FILE="$ROOT/package.json"
README_FILE="$ROOT/readme.txt"
DIST_DIR="$ROOT/releases"

# Files/folders that land inside the ZIP. Anything not listed here is excluded.
INCLUDES=(
	"404-to-301-redirects-importer.php"
	"readme.txt"
	"includes"
	"build"
	"languages"
)

stage=""
cleanup() {
	[[ -n "$stage" ]] && rm -rf "$stage"
}
trap cleanup EXIT

log()  { printf '\n==> %s\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

command -v wp  >/dev/null 2>&1 || fail "WP-CLI ('wp') is required. See https://wp-cli.org/"
command -v zip >/dev/null 2>&1 || fail "'zip' is required."

cd "$ROOT"

#
# 1. Version consistency.
#
log "Checking version consistency"

plugin_version=$(grep -E '^[[:space:]]*\*?[[:space:]]*Version:' "$PLUGIN_FILE" \
	| head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')
pkg_version=$(node -p "require('$PKG_FILE').version")
readme_stable=$(grep -E '^Stable tag:' "$README_FILE" \
	| head -1 | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '\r')

printf '    plugin header:    %s\n' "$plugin_version"
printf '    package.json:     %s\n' "$pkg_version"
printf '    readme stable:    %s\n' "$readme_stable"

if [[ "$plugin_version" != "$pkg_version" ]]; then
	fail "Version mismatch — sync both values before packing."
fi

# WordPress.org reads Stable tag: from readme.txt to decide which
# version to actually serve. A mismatch silently ships the previous
# release, so block the pack until it's caught up.
if [[ "$plugin_version" != "$readme_stable" ]]; then
	fail "readme.txt Stable tag ($readme_stable) doesn't match plugin version ($plugin_version)."
fi

VERSION="$plugin_version"

#
# 2. POT.
#
log "Generating POT"

mkdir -p "$ROOT/languages"
wp i18n make-pot "$ROOT" "$ROOT/languages/$SLUG.pot" \
	--slug="$SLUG" \
	--domain="$SLUG" \
	--exclude="node_modules,build,releases,bin,tests,vendor"

#
# 3. Build assets.
#
log "Building assets"
npm run build

#
# 4. Stage and zip.
#
log "Packing $SLUG-$VERSION.zip"

mkdir -p "$DIST_DIR"
zip_path="$DIST_DIR/$SLUG-$VERSION.zip"
rm -f "$zip_path"

stage="$(mktemp -d)"
plugin_stage="$stage/$SLUG"
mkdir -p "$plugin_stage"

for item in "${INCLUDES[@]}"; do
	if [[ -e "$ROOT/$item" ]]; then
		cp -R "$ROOT/$item" "$plugin_stage/"
	else
		printf 'WARNING: %s not found, skipping\n' "$item" >&2
	fi
done

find "$plugin_stage" -name '.DS_Store' -delete

(cd "$stage" && zip -rq "$zip_path" "$SLUG")

printf '\nPacked: %s\n' "$zip_path"
ls -lh "$zip_path"
