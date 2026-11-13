#!/usr/bin/env bash
# Installs a clean WordPress with WP_DEBUG on, activates the built plugin and
# checks what the WordPress.org review checks:
#   - activation prints nothing and logs no error, warning or notice;
#   - the events table is created with the site's table prefix;
#   - no request leaves the site while crawler verification is off, on
#     activation or when every scheduled task runs;
#   - once verification is on, every request goes to a URL listed under
#     External services in readme.txt;
#   - turning verification off deletes the stored lists and the schedule.
#
#   bin/activation-test.sh build/an10-ai-crawler-analytics
#
# Needs wp-cli (or $WP_CLI) and a MySQL database: DB_NAME, DB_USER, DB_PASSWORD,
# DB_HOST. Uses its own table prefix and directory, and removes both at the end.
set -euo pipefail

plugin_dir=$(cd "$1" && pwd)
readme="$plugin_dir/readme.txt"
wp_bin=${WP_CLI:-wp}
site=${WP_PATH:-/tmp/an10-activation-test}
prefix=an10test_
log="$site/wp-content/http-requests.log"
debug_log="$site/wp-content/debug.log"
slug=an10-ai-crawler-analytics

# `command` so this function never calls itself when $wp_bin is plain "wp".
wp() { command $wp_bin --path="$site" "$@"; }
fail() {
	echo "activation-test: FAIL: $*" >&2
	exit 1
}
cleanup() {
	wp db clean --yes --quiet >/dev/null 2>&1 || true
	rm -rf "$site"
}
trap cleanup EXIT

rm -rf "$site"
mkdir -p "$site"
wp core download --skip-content --quiet
mkdir -p "$site/wp-content/plugins" "$site/wp-content/mu-plugins"
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" \
	--dbhost="$DB_HOST" --dbprefix="$prefix" --skip-check --quiet
wp config set WP_DEBUG true --raw --quiet
wp config set WP_DEBUG_LOG true --raw --quiet
wp core install --url=http://an10-test.local --title='Activation test' \
	--admin_user=an10_test --admin_password="$(head -c 18 /dev/urandom | base64)" \
	--admin_email=admin@an10-test.local --skip-email --quiet

# Log every outgoing HTTP request WordPress makes.
cat >"$site/wp-content/mu-plugins/log-http.php" <<'PHP'
<?php
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		file_put_contents( WP_CONTENT_DIR . '/http-requests.log', $url . "\n", FILE_APPEND );
		return $pre;
	},
	10,
	3
);
PHP
cp -r "$plugin_dir" "$site/wp-content/plugins/$slug"
: >"$log"

external() { grep -v '^http://an10-test.local' "$log" || true; }

# 1. Activation: silent and clean.
out=$(wp plugin activate "$slug" 2>&1)
if printf '%s\n' "$out" | grep -Eiq 'warning|notice|deprecated|fatal|unexpected output'; then
	fail "activation was not clean: $out"
fi
[ ! -s "$debug_log" ] || fail "activation wrote to debug.log: $(cat "$debug_log")"

# 2. The table, with the site's prefix.
[ "$(wp db query "SHOW TABLES LIKE '${prefix}an10_events'" --skip-column-names)" = "${prefix}an10_events" ] ||
	fail "the ${prefix}an10_events table was not created"

# 3. Verification starts off, and nothing leaves the site.
[ "$(wp eval 'echo AN10_Verifier::enabled() ? "on" : "off";')" = off ] || fail "verification is on after activation"
wp cron event run --due-now --quiet >/dev/null 2>&1 || true
wp cron event run an10_daily an10_weekly --quiet >/dev/null 2>&1 || true
[ -z "$(external)" ] || fail "requests left the site with verification off: $(external | tr '\n' ' ')"

# 4. After opting in, only documented URLs are requested.
wp eval 'AN10_Verifier::set_enabled( true );'
wp cron event run an10_refresh_lists --quiet >/dev/null 2>&1 || true
[ -n "$(external)" ] || fail "no list was downloaded after verification was turned on"
while IFS= read -r url; do
	grep -qF "$url" "$readme" || fail "$url is requested but not listed under External services in readme.txt"
done < <(external | sort -u)

# 5. Opting out deletes the lists and the schedule.
wp eval 'AN10_Verifier::set_enabled( false );'
[ "$(wp eval 'echo count( (array) get_option( AN10_Verifier::OPTION, array() ) );')" = 0 ] ||
	fail "the stored lists remain after verification was turned off"
if wp cron event list --fields=hook --format=csv | grep -q an10_refresh_lists; then
	fail "a list download is still scheduled after verification was turned off"
fi

# 6. Deactivation is clean too.
out=$(wp plugin deactivate "$slug" 2>&1)
if printf '%s\n' "$out" | grep -Eiq 'warning|notice|deprecated|fatal'; then
	fail "deactivation was not clean: $out"
fi
[ ! -s "$debug_log" ] || fail "debug.log is not empty: $(cat "$debug_log")"

echo "activation-test: all checks passed ($(external | sort -u | wc -l) documented list URLs after opt-in)"
