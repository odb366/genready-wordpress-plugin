#!/usr/bin/env bash
# Run every spec on its blueprint, one Playground at a time, and print a pass/fail table.
#
# Usage: bash e2e/run-all.sh [work-dir]
#   work-dir: where the release copy for the uninstall and Plugin Check runs is built
#             (default e2e/test-results/work, which git ignores).
#
# Needs the local GenReady test server on 127.0.0.1:5002 and the test accounts in
# ~/.genready-e2e-users.json. GENREADY_E2E_SEED points to seed-users.cjs: it makes the Pro account
# own the 127.0.0.1 domain again (a scheduled recheck on the server unverifies it), and moves the
# domain to the Free account for the free-plan spec. It also rotates both API keys.
set -uo pipefail

E2E_DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$E2E_DIR/.." && pwd)
WORK=${1:-$E2E_DIR/test-results/work}
SEED=${GENREADY_E2E_SEED:-$ROOT/.superpowers/sdd/2026-10-08-wordpress-plugin-b-plugin/e2e-tools/seed-users.cjs}
SUMMARY="$E2E_DIR/test-results/summary.txt"
mkdir -p "$WORK/genready" "$E2E_DIR/test-results"
: >"$SUMMARY"

curl -sf -m 5 -o /dev/null http://127.0.0.1:5002/health || { echo "BLOCKED: the GenReady test server on 127.0.0.1:5002 does not answer" >&2; exit 3; }

seed() {
	if [ -f "$SEED" ]; then
		node "$SEED" "--owner=$1" >/dev/null || { echo "BLOCKED: seed-users.cjs --owner=$1 failed" >&2; exit 3; }
	else
		echo "warning: no seed script at $SEED; the domain owner stays as it is" >&2
	fi
}

# The uninstall and Plugin Check runs use the release copy (no dev files).
rsync -a --delete --exclude-from="$ROOT/.distignore" "$ROOT/" "$WORK/genready/"

failed=0
run() {
	local label=$1 blueprint=$2 spec=$3
	shift 3
	local start end status
	start=$(date +%s)
	env "$@" bash "$E2E_DIR/run.sh" "$E2E_DIR/blueprints/$blueprint" "specs/$spec"
	status=$?
	end=$(date +%s)
	if [ "$status" -eq 0 ]; then
		printf '| %-34s | %-20s | PASS | %4ss |\n' "$label" "$blueprint" "$((end - start))" | tee -a "$SUMMARY"
	else
		printf '| %-34s | %-20s | FAIL (%s) | %4ss |\n' "$label" "$blueprint" "$status" "$((end - start))" | tee -a "$SUMMARY"
		failed=1
	fi
}

seed pro
run "connect-key (PHP 8.3)" base.json connect-key.spec.ts
run "connect-key (PHP 7.4)" base.json connect-key.spec.ts GENREADY_E2E_PHP=7.4
run "connect-oauth" base.json connect-oauth.spec.ts
run "physical-robots" physical-robots.json physical-robots.spec.ts
run "blog-hidden" blog-hidden.json blog-hidden.spec.ts
run "llms" base.json llms.spec.ts
run "llms (Yoast llms.txt on)" yoast.json llms.spec.ts GENREADY_E2E_SEO=yoast
run "seo-plugins (Yoast)" yoast.json seo-plugins.spec.ts GENREADY_E2E_SEO=yoast
run "seo-plugins (Rank Math)" rankmath.json seo-plugins.spec.ts GENREADY_E2E_SEO=rankmath
run "seo-plugins (All in One SEO)" aioseo.json seo-plugins.spec.ts GENREADY_E2E_SEO=aioseo
run "no-frontend-tracking" base.json no-frontend-tracking.spec.ts
run "uninstall (release copy)" uninstall.json uninstall.spec.ts GENREADY_E2E_PLUGIN_DIR="$WORK/genready" GENREADY_E2E_MOUNT_AT=/e2e-src/genready
run "plugin-check (release copy)" plugin-check.json plugin-check.spec.ts GENREADY_E2E_PLUGIN_DIR="$WORK/genready" GENREADY_E2E_PLUGIN_CHECK=1
seed free
run "free-plan" free.json free-plan.spec.ts
seed pro

echo
cat "$SUMMARY"
exit "$failed"
