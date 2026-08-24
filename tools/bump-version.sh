#!/usr/bin/env bash

set -euo pipefail

usage() {
	printf 'Usage: %s <patch|minor|major> [changelog item ...]\n' "$(basename "$0")" >&2
	printf 'Example: %s minor "Add complete previous-day reports"\n' "$(basename "$0")" >&2
}

if [[ $# -lt 1 ]]; then
	usage
	exit 2
fi

bump_type=$1
shift

case "$bump_type" in
	patch|minor|major)
		;;
	*)
		printf 'Unknown bump type: %s\n' "$bump_type" >&2
		usage
		exit 2
		;;
esac

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_file="$repo_root/jetlinez-woocommerce-invoice.php"
readme_file="$repo_root/readme.txt"
manifest_file="$repo_root/update.json"

plugin_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$plugin_file" | head -n 1)"
constant_version="$(sed -n "s/^define( 'JLWI_VERSION', '\([^']*\)' );/\1/p" "$plugin_file" | head -n 1)"
stable_version="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$readme_file" | head -n 1)"
manifest_version="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$manifest_file" | head -n 1)"

if [[ ! "$plugin_version" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
	printf 'Current plugin version is not a supported SemVer value: %s\n' "$plugin_version" >&2
	exit 1
fi

if [[ "$plugin_version" != "$constant_version" || "$plugin_version" != "$stable_version" || "$plugin_version" != "$manifest_version" ]]; then
	printf 'Version mismatch: header=%s constant=%s stable_tag=%s manifest=%s\n' \
		"$plugin_version" "$constant_version" "$stable_version" "$manifest_version" >&2
	exit 1
fi

IFS='.' read -r major minor patch <<< "$plugin_version"

case "$bump_type" in
	patch)
		new_version="$major.$minor.$((patch + 1))"
		;;
	minor)
		new_version="$major.$((minor + 1)).0"
		;;
	major)
		new_version="$((major + 1)).0.0"
		;;
esac

if [[ $# -eq 0 ]]; then
	set -- 'به‌روزرسانی افزونه.'
fi

html_escape() {
	local value=$1
	value=${value//$'\r'/ }
	value=${value//$'\n'/ }
	value=${value//&/\&amp;}
	value=${value//\\/\&#92;}
	value=${value//</\&lt;}
	value=${value//>/\&gt;}
	value=${value//\"/\&quot;}
	printf '%s' "$value"
}

changelog_items=''
for item in "$@"; do
	if [[ -n "$item" ]]; then
		changelog_items+='<li>'"$(html_escape "$item")"'</li>'
	fi
done

if [[ -z "$changelog_items" ]]; then
	printf 'At least one non-empty changelog item is required.\n' >&2
	exit 2
fi

work_dir="$(mktemp -d "${TMPDIR:-/tmp}/jlwi-bump-version.XXXXXX")"
trap 'rm -rf -- "$work_dir"' EXIT

cp "$plugin_file" "$work_dir/plugin.php"
cp "$readme_file" "$work_dir/readme.txt"
cp "$manifest_file" "$work_dir/update.json"

sed -i -E "s/^ \* Version:[[:space:]]*.*/ * Version:     $new_version/" "$work_dir/plugin.php"
sed -i -E "s/^define\( 'JLWI_VERSION', '[^']*' \);/define( 'JLWI_VERSION', '$new_version' );/" "$work_dir/plugin.php"
sed -i -E "s/^Stable tag:[[:space:]]*.*/Stable tag: $new_version/" "$work_dir/readme.txt"
sed -i -E "s/^([[:space:]]*\"version\"[[:space:]]*:[[:space:]]*)\"[^\"]*\"/\1\"$new_version\"/" "$work_dir/update.json"
sed -i -E "s/^([[:space:]]*\"last_updated\"[[:space:]]*:[[:space:]]*)\"[^\"]*\"/\1\"$(date '+%Y-%m-%d %H:%M:%S')\"/" "$work_dir/update.json"

old_changelog="$(sed -n 's/^[[:space:]]*"changelog"[[:space:]]*:[[:space:]]*"\(.*\)"[[:space:]]*$/\1/p' "$work_dir/update.json")"
if [[ -z "$old_changelog" ]]; then
	printf 'Could not read sections.changelog from update.json.\n' >&2
	exit 1
fi

new_changelog="<h4>$new_version</h4><ul>$changelog_items</ul>$old_changelog"
manifest_temp="$work_dir/update-with-changelog.json"
awk -v replacement="    \"changelog\": \"$new_changelog\"" '
	BEGIN { replaced = 0 }
	/^[[:space:]]*"changelog"[[:space:]]*:/ && ! replaced {
		print replacement
		replaced = 1
		next
	}
	{ print }
	END { if ( ! replaced ) exit 1 }
' "$work_dir/update.json" > "$manifest_temp"
mv "$manifest_temp" "$work_dir/update.json"

new_plugin_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$work_dir/plugin.php" | head -n 1)"
new_constant_version="$(sed -n "s/^define( 'JLWI_VERSION', '\([^']*\)' );/\1/p" "$work_dir/plugin.php" | head -n 1)"
new_stable_version="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$work_dir/readme.txt" | head -n 1)"
new_manifest_version="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$work_dir/update.json" | head -n 1)"

if [[ "$new_version" != "$new_plugin_version" || "$new_version" != "$new_constant_version" || "$new_version" != "$new_stable_version" || "$new_version" != "$new_manifest_version" ]]; then
	printf 'Version bump validation failed; no project files were changed.\n' >&2
	exit 1
fi

cp "$work_dir/plugin.php" "$plugin_file"
cp "$work_dir/readme.txt" "$readme_file"
cp "$work_dir/update.json" "$manifest_file"

printf 'Bumped version: %s -> %s (%s)\n' "$plugin_version" "$new_version" "$bump_type"
printf 'Updated plugin header, JLWI_VERSION, Stable tag, update manifest timestamp, and changelog.\n'
