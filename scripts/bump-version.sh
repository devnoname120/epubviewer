#!/usr/bin/env bash

set -euo pipefail

usage() {
	echo "Usage: $0 <version>"
	echo
	echo "Example:"
	echo "  $0 1.9.2"
}

update_xml_version() {
	local file="$1"
	local new_version="$2"
	local tmp_file

	tmp_file="$(mktemp)"
	if ! awk -v v="$new_version" '
		BEGIN { updated = 0 }
		{
			if (!updated) {
				replaced = sub(/<version>[^<]*<\/version>/, "<version>" v "</version>")
				if (replaced == 1) {
					updated = 1
				}
			}
			print
		}
		END {
			if (!updated) {
				exit 42
			}
		}
	' "$file" > "$tmp_file"; then
		rm -f "$tmp_file"
		echo "Could not update <version> tag in $file"
		exit 1
	fi

	mv "$tmp_file" "$file"
}

update_json_version_key() {
	local file="$1"
	local new_version="$2"
	local tmp_file

	tmp_file="$(mktemp)"
	if ! awk -v v="$new_version" '
		BEGIN { updated = 0 }
		{
			if (!updated) {
				replaced = sub(/"version"[[:space:]]*:[[:space:]]*"[^"]*"/, "\"version\": \"" v "\"")
				if (replaced == 1) {
					updated = 1
				}
			}
			print
		}
		END {
			if (!updated) {
				exit 42
			}
		}
	' "$file" > "$tmp_file"; then
		rm -f "$tmp_file"
		echo "Could not update version key in $file"
		exit 1
	fi

	mv "$tmp_file" "$file"
}

prepend_changelog_boilerplate() {
	local file="$1"
	local new_version="$2"
	local today
	local tmp_file

	if [[ ! -f "$file" ]]; then
		echo "Missing $file"
		exit 1
	fi

	if grep -Eq "^##[[:space:]]+${new_version}[[:space:]]+-[[:space:]]+[0-9]{4}-[0-9]{2}-[0-9]{2}$" "$file"; then
		echo "CHANGELOG already contains version $new_version"
		exit 1
	fi

	today="$(date +%F)"
	tmp_file="$(mktemp)"
	{
		echo "## ${new_version} - ${today}"
		echo
		echo "### Added"
		echo
		echo "- TODO"
		echo
		echo "### Changed"
		echo
		echo "- TODO"
		echo
		echo "### Fixed"
		echo
		echo "- TODO"
		echo
		cat "$file"
	} > "$tmp_file"

	mv "$tmp_file" "$file"
}

ensure_changelog_version_absent() {
	local file="$1"
	local new_version="$2"

	if grep -Eq "^##[[:space:]]+${new_version}[[:space:]]+-[[:space:]]+[0-9]{4}-[0-9]{2}-[0-9]{2}$" "$file"; then
		echo "CHANGELOG already contains version $new_version"
		exit 1
	fi
}

if [[ $# -eq 0 ]]; then
	usage
	exit 1
fi

if [[ "$1" == "-h" || "$1" == "--help" ]]; then
	usage
	exit 0
fi

if [[ $# -ne 1 ]]; then
	usage
	exit 1
fi

version="$1"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid version: $version"
	echo "Expected format: X.Y.Z (for example 1.9.2)"
	exit 1
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

current_version="$(sed -n 's/.*<version>\([^<]*\)<\/version>.*/\1/p' appinfo/info.xml | head -n 1)"
if [[ -z "$current_version" ]]; then
	echo "Could not determine current version from appinfo/info.xml"
	exit 1
fi

if [[ "$current_version" == "$version" ]]; then
	echo "Version is already $version"
	exit 1
fi

ensure_changelog_version_absent CHANGELOG.md "$version"

echo "Bumping version: $current_version -> $version"

update_xml_version appinfo/info.xml "$version"
update_json_version_key composer.json "$version"
prepend_changelog_boilerplate CHANGELOG.md "$version"

npm version "$version" --no-git-tag-version
npm install

composer update
composer install

echo
echo "Version bump complete. Updated files:"
git --no-pager diff -- CHANGELOG.md appinfo/info.xml composer.json composer.lock package.json package-lock.json
echo
echo "Please update CHANGELOG.md for version ${version} (replace TODO entries) before committing."
