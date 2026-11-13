#!/usr/bin/env bash
# Build the release the way WordPress.org will see it: checks first, then only
# the files the plugin needs at runtime (everything not in .distignore), in
# build/an10-ai-crawler-analytics/ and build/an10-ai-crawler-analytics-<version>.zip.
set -euo pipefail
cd "$(dirname "$0")/.."

slug=an10-ai-crawler-analytics
version=$(sed -n 's/^ \* Version: *//p' "$slug.php")
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)
constant=$(sed -n "s/^define( 'AN10_VERSION', '\(.*\)' );/\1/p" "$slug.php")
if [ "$version" != "$stable" ] || [ "$version" != "$constant" ]; then
	echo "Version mismatch: header $version, readme Stable tag $stable, AN10_VERSION $constant" >&2
	exit 1
fi

php bin/generate.php --check
vendor/bin/phpcs -q
vendor/bin/phpunit --no-coverage >/dev/null

rm -rf build
mkdir -p "build/$slug"
rsync -a --exclude-from=.distignore ./ "build/$slug/"
(cd build && zip -qrX "$slug-$version.zip" "$slug")
echo "build/$slug-$version.zip"
