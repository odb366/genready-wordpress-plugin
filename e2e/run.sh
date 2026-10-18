#!/usr/bin/env bash
# Run Playwright specs against one WordPress Playground site.
#
# Usage: bash e2e/run.sh <blueprint.json> [playwright args, for example specs/connect-key.spec.ts]
#
# Environment:
#   GENREADY_E2E_PHP           PHP version for Playground (default 8.3)
#   GENREADY_E2E_PLUGIN_DIR    plugin folder to mount (default: this repo)
#   GENREADY_E2E_MOUNT_AT      where the plugin folder is mounted (default /wordpress/wp-content/plugins/genready)
#   GENREADY_E2E_SEO           yoast | rankmath | aioseo | none (passed to the specs, default none)
#   GENREADY_E2E_SITE_URL      WordPress site URL when it is not http://127.0.0.1:9411 (multisite needs one
#                              without a port; the multisite spec maps it back to 9411 in the browser)
#   GENREADY_E2E_WORKERS       Playground PHP workers (default 1; the account spec needs 2, spec 7.5)
#   GENREADY_E2E_MIN_MB        free memory that the host check needs (default 1200)
#
# Host safety: the script refuses to start while a GenReady deploy runs or when the host has
# 1200 MB or less available memory (it waits up to 10 minutes). It runs one Playground (1 worker
# unless GENREADY_E2E_WORKERS says otherwise) on 127.0.0.1:9411 and stops it on exit.
set -euo pipefail

E2E_DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$E2E_DIR/.." && pwd)
BLUEPRINT=${1:?usage: run.sh <blueprint.json> [playwright args]}
shift
PHP_VERSION=${GENREADY_E2E_PHP:-8.3}
PLUGIN_DIR=${GENREADY_E2E_PLUGIN_DIR:-$ROOT}
MOUNT_AT=${GENREADY_E2E_MOUNT_AT:-/wordpress/wp-content/plugins/genready}
PORT=9411
LOG_DIR="$E2E_DIR/test-results"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/playground-$(basename "$BLUEPRINT" .json).log"

# Playground CLI 3.1.57 ignores --php for a blueprint file and uses the blueprint's preferredVersions
# (default PHP 8.5), so the PHP version goes into a copy of the blueprint.
EFFECTIVE="$LOG_DIR/blueprint-$(basename "$BLUEPRINT")"
node -e '
const fs = require("fs");
const [src, dst, php] = process.argv.slice(1);
const blueprint = JSON.parse(fs.readFileSync(src, "utf8"));
blueprint.preferredVersions = { wp: "latest", ...(blueprint.preferredVersions || {}), php };
fs.writeFileSync(dst, JSON.stringify(blueprint, null, "\t"));
' "$BLUEPRINT" "$EFFECTIVE" "$PHP_VERSION"

# shellcheck source=lib/host-check.sh
. "$E2E_DIR/lib/host-check.sh"
wait_for_host

setsid npx -y @wp-playground/cli@3.1.57 server \
	--port="$PORT" --php="$PHP_VERSION" --wp=latest --workers="${GENREADY_E2E_WORKERS:-1}" \
	${GENREADY_E2E_SITE_URL:+--site-url="$GENREADY_E2E_SITE_URL"} \
	--mount="$PLUGIN_DIR:$MOUNT_AT" \
	--mount="$E2E_DIR/mu-plugins:/wordpress/wp-content/mu-plugins" \
	--blueprint="$EFFECTIVE" >"$LOG" 2>&1 &
PG=$!

stop_playground() {
	kill -TERM -- "-$PG" 2>/dev/null || true
	for _ in $(seq 1 20); do
		[ -n "$(playground_pids)" ] || break
		sleep 0.5
	done
	for pid in $(playground_pids); do kill -KILL "$pid" 2>/dev/null || true; done
}
trap stop_playground EXIT

ready=0
for _ in $(seq 1 120); do
	if ! kill -0 "$PG" 2>/dev/null; then
		echo "Playground exited early; see $LOG" >&2
		exit 4
	fi
	if curl -sf -o /dev/null "http://127.0.0.1:$PORT/"; then
		ready=1
		break
	fi
	sleep 2
done
if [ "$ready" -ne 1 ]; then
	echo "Playground did not answer in 240 s; see $LOG" >&2
	exit 4
fi

powered=$(curl -s -o /dev/null -D - "http://127.0.0.1:$PORT/wp-login.php" | tr -d '\r' | awk -F': ' 'tolower($1)=="x-powered-by" {print $2}')
case "$powered" in
	"PHP/$PHP_VERSION".*) echo "Playground runs $powered" ;;
	*) echo "Playground runs '$powered', not PHP $PHP_VERSION" >&2; exit 5 ;;
esac

cd "$E2E_DIR"
GENREADY_E2E_SEO=${GENREADY_E2E_SEO:-none} GENREADY_E2E_PHP="$PHP_VERSION" \
	npx playwright test "$@"
