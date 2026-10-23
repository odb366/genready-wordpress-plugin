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
#   GENREADY_E2E_ABORT_MB      the run stops when available memory falls below this while Playground runs
#                              (default 500; checked every 3 s)
#
# Host safety: the script refuses to start while a GenReady deploy runs or when the host has
# GENREADY_E2E_MIN_MB (default 1200) or less available memory (it waits up to 10 minutes). It runs one Playground (1 worker
# unless GENREADY_E2E_WORKERS says otherwise) on 127.0.0.1:9411 and stops it on exit. While it runs,
# a watchdog reads the available memory every 3 s. Below GENREADY_E2E_ABORT_MB it stops Playground and
# the tests, and the run fails with "ABORTED: host memory below N MB" (exit 6). At the end the script
# prints the lowest available memory that the watchdog saw.
set -euo pipefail

E2E_DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$E2E_DIR/.." && pwd)
BLUEPRINT=${1:?usage: run.sh <blueprint.json> [playwright args]}
shift
PHP_VERSION=${GENREADY_E2E_PHP:-8.3}
PLUGIN_DIR=${GENREADY_E2E_PLUGIN_DIR:-$ROOT}
MOUNT_AT=${GENREADY_E2E_MOUNT_AT:-/wordpress/wp-content/plugins/genready}
PORT=9411
ABORT_MB=${GENREADY_E2E_ABORT_MB:-500}
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

PG=
PW=
WATCH=
MIN_FILE="$LOG_DIR/memory-min-$$.txt"
ABORT_FILE="$LOG_DIR/memory-abort-$$.txt"
rm -f "$ABORT_FILE" "$ABORT_FILE.pw"

stop_playground() {
	if [ -n "$PG" ]; then kill -TERM -- "-$PG" 2>/dev/null || true; fi
	for _ in $(seq 1 20); do
		[ -n "$(playground_pids)" ] || break
		sleep 0.5
	done
	for pid in $(playground_pids); do kill -KILL "$pid" 2>/dev/null || true; done
}

# Stop a process and every process under it (npx, the Playwright runner, its workers, the browser).
kill_tree() {
	local child
	for child in $(pgrep -P "$1" || true); do kill_tree "$child"; done
	kill -TERM "$1" 2>/dev/null || true
}

# True when the PID still runs a command whose line contains the pattern (guards against PID reuse).
runs() {
	ps -o args= -p "$1" 2>/dev/null | grep -q -- "$2"
}

# True while the script runs (kill -0 also succeeds for a zombie, so a Z state counts as gone).
script_alive() {
	kill -0 "$1" 2>/dev/null && [ "$(ps -o stat= -p "$1" 2>/dev/null | cut -c1)" != Z ]
}

available_mb() {
	free -m | awk 'NR==2 {print $7}'
}

on_exit() {
	local status=$?
	if [ -n "$WATCH" ]; then
		kill "$WATCH" 2>/dev/null || true
		wait "$WATCH" 2>/dev/null || true
	fi
	if [ -n "$PW" ]; then kill_tree "$PW"; fi
	stop_playground
	if [ -f "$MIN_FILE" ]; then
		echo "host memory: the lowest available memory during the run was $(cat "$MIN_FILE") MB"
	fi
	rm -f "$MIN_FILE" "$ABORT_FILE.pw"
	if [ -f "$ABORT_FILE" ]; then
		echo "ABORTED: host memory below $ABORT_MB MB ($(cat "$ABORT_FILE") MB available)" >&2
		rm -f "$ABORT_FILE"
		exit 6
	fi
	exit "$status"
}

setsid npx -y @wp-playground/cli@3.1.57 server \
	--port="$PORT" --php="$PHP_VERSION" --wp=latest --workers="${GENREADY_E2E_WORKERS:-1}" \
	${GENREADY_E2E_SITE_URL:+--site-url="$GENREADY_E2E_SITE_URL"} \
	--mount="$PLUGIN_DIR:$MOUNT_AT" \
	--mount="$E2E_DIR/mu-plugins:/wordpress/wp-content/mu-plugins" \
	--blueprint="$EFFECTIVE" >"$LOG" 2>&1 &
PG=$!
trap on_exit EXIT

# Memory watchdog: records the lowest available memory, and stops Playground and the tests below ABORT_MB.
# It also ends when the script is gone (for example after a SIGKILL, when on_exit cannot run).
available_mb >"$MIN_FILE"
(
	while :; do
		if ! script_alive "$$"; then
			# The script died without its clean-up: stop what it started, if those PIDs still run it.
			if runs "$PG" wp-playground; then kill -TERM -- "-$PG" 2>/dev/null || true; fi
			if [ -f "$ABORT_FILE.pw" ] && runs "$(cat "$ABORT_FILE.pw")" playwright; then kill_tree "$(cat "$ABORT_FILE.pw")"; fi
			rm -f "$MIN_FILE" "$ABORT_FILE" "$ABORT_FILE.pw"
			exit 0
		fi
		avail=$(available_mb)
		if [ "$avail" -lt "$(cat "$MIN_FILE")" ]; then echo "$avail" >"$MIN_FILE"; fi
		if [ "$avail" -lt "$ABORT_MB" ] && [ ! -f "$ABORT_FILE" ]; then echo "$avail" >"$ABORT_FILE"; fi
		# After an abort, keep stopping Playground and the tests (they may start later) until the script exits.
		if [ -f "$ABORT_FILE" ]; then
			kill -TERM -- "-$PG" 2>/dev/null || true
			if [ -f "$ABORT_FILE.pw" ]; then kill_tree "$(cat "$ABORT_FILE.pw")"; fi
			sleep 1
			continue
		fi
		sleep 3
	done
) &
WATCH=$!

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
# One check here too, so the tests never start below the limit (the watchdog samples only every 3 s).
avail_now=$(available_mb)
if [ ! -f "$ABORT_FILE" ] && [ "$avail_now" -lt "$ABORT_MB" ]; then echo "$avail_now" >"$ABORT_FILE"; fi
[ ! -f "$ABORT_FILE" ] || exit 6
# In the background, so the watchdog can stop it (kill_tree) while the script waits.
GENREADY_E2E_SEO=${GENREADY_E2E_SEO:-none} GENREADY_E2E_PHP="$PHP_VERSION" \
	npx playwright test "$@" &
PW=$!
echo "$PW" >"$ABORT_FILE.pw"
status=0
wait "$PW" || status=$?
# The runner is reaped: on_exit and the watchdog must not signal its PID again.
rm -f "$ABORT_FILE.pw"
PW=
exit "$status"
