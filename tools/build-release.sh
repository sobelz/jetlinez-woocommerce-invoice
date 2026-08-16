#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_file="$repo_root/jetlinez-woocommerce-invoice.php"
manifest_file="$repo_root/update.json"
slug="jetlinez-woocommerce-invoice"

if [[ -n "$(git -C "$repo_root" status --porcelain --untracked-files=all)" ]]; then
	printf 'The working tree is not clean. Commit the release before building its ZIP.\n' >&2
	exit 1
fi

plugin_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$plugin_file" | head -n 1)"
constant_version="$(sed -n "s/^define( 'JLWI_VERSION', '\([^']*\)' );/\1/p" "$plugin_file" | head -n 1)"
manifest_version="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$manifest_file" | head -n 1)"

if [[ "$plugin_version" != "$constant_version" || "$plugin_version" != "$manifest_version" ]]; then
	printf 'Version mismatch: header=%s constant=%s manifest=%s\n' "$plugin_version" "$constant_version" "$manifest_version" >&2
	exit 1
fi

output_file="${1:-$repo_root/$slug.zip}"
temp_file="$(mktemp "${TMPDIR:-/tmp}/${slug}.XXXXXX.zip")"
trap 'rm -f "$temp_file"' EXIT

git --git-dir="$git_dir" -C "$repo_root" archive \
	--format=zip \
	--prefix="$slug/" \
	--output="$temp_file" \
	HEAD

mv "$temp_file" "$output_file"
trap - EXIT

printf 'Built %s (version %s)\n' "$output_file" "$plugin_version"
if command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$output_file"
fi
