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
#
# Host safety: the script refuses to start while a GenReady deploy runs or when the host has
# 1200 MB or less available memory (it waits up to 10 minutes). It runs one Playground with
# one worker on 127.0.0.1:9411 and stops it on exit.
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

# shellcheck source=lib/host-check.sh
. "$E2E_DIR/lib/host-check.sh"
wait_for_host

setsid npx -y @wp-playground/cli@3.1.57 server \
	--port="$PORT" --php="$PHP_VERSION" --wp=latest --workers=1 \
	--mount="$PLUGIN_DIR:$MOUNT_AT" \
	--mount="$E2E_DIR/mu-plugins:/wordpress/wp-content/mu-plugins" \
	--blueprint="$BLUEPRINT" >"$LOG" 2>&1 &
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

cd "$E2E_DIR"
GENREADY_E2E_SEO=${GENREADY_E2E_SEO:-none} GENREADY_E2E_PHP="$PHP_VERSION" \
	npx playwright test "$@"
