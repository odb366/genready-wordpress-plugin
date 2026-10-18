#!/usr/bin/env bash
# Run every spec on its blueprint, one Playground at a time, and print a pass/fail table.
#
# Usage: bash e2e/run-all.sh [work-dir]
#   work-dir: where the release copy and the release zip are built
#             (default e2e/test-results/work, which git ignores).
#   GENREADY_E2E_RELEASE: folder with a genready-1.0.0.zip to test instead of building one.
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
ANON=${GENREADY_E2E_ANON:-$ROOT/.superpowers/sdd/2026-10-08-wordpress-plugin-b-plugin/e2e-tools/seed-anon-scan.cjs}
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

anon_reset() {
	node "$ANON" reset >/dev/null || { echo "BLOCKED: seed-anon-scan.cjs reset failed" >&2; exit 3; }
}

# The uninstall and Plugin Check runs use the release copy (no dev files).
rsync -a --delete --exclude-from="$ROOT/.distignore" "$ROOT/" "$WORK/genready/"
# The release runs install a zip of that copy.
RELEASE=${GENREADY_E2E_RELEASE:-}
if [ -z "$RELEASE" ]; then
	RELEASE="$WORK/release"
	mkdir -p "$RELEASE"
	(cd "$WORK" && python3 -I -c 'import shutil; shutil.make_archive("release/genready-1.0.0", "zip", ".", "genready")')
fi

failed=0
run() {
	local label=$1 blueprint=$2 spec=$3
	shift 3
	local start end status php=8.3 arg
	for arg in "$@"; do
		case "$arg" in GENREADY_E2E_PHP=*) php=${arg#GENREADY_E2E_PHP=} ;; esac
	done
	start=$(date +%s)
	env "$@" bash "$E2E_DIR/run.sh" "$E2E_DIR/blueprints/$blueprint" "specs/$spec"
	status=$?
	end=$(date +%s)
	if [ "$status" -eq 0 ]; then
		printf '| %-30s | %-26s | %-3s | PASS | %4ss |\n' "$label" "$blueprint" "$php" "$((end - start))" | tee -a "$SUMMARY"
	else
		printf '| %-30s | %-26s | %-3s | FAIL (%s) | %4ss |\n' "$label" "$blueprint" "$php" "$status" "$((end - start))" | tee -a "$SUMMARY"
		failed=1
	fi
}

COPY=(GENREADY_E2E_PLUGIN_DIR="$WORK/genready")
ZIP=(GENREADY_E2E_PLUGIN_DIR="$RELEASE" GENREADY_E2E_MOUNT_AT=/e2e-release)

seed pro
run "connect-key" base.json connect-key.spec.ts
run "connect-key" base.json connect-key.spec.ts GENREADY_E2E_PHP=7.4
run "connect-oauth" base.json connect-oauth.spec.ts
run "client-document" base.json client-document.spec.ts
run "physical-robots" physical-robots.json physical-robots.spec.ts
run "physical-robots" physical-robots.json physical-robots.spec.ts GENREADY_E2E_PHP=7.4
run "blog-hidden" blog-hidden.json blog-hidden.spec.ts
run "llms" base.json llms.spec.ts
run "llms" base.json llms.spec.ts GENREADY_E2E_PHP=7.4
run "llms (Yoast llms.txt on)" yoast.json llms.spec.ts GENREADY_E2E_SEO=yoast
run "seo-plugins (Yoast)" yoast.json seo-plugins.spec.ts GENREADY_E2E_SEO=yoast
run "seo-plugins (Yoast)" yoast.json seo-plugins.spec.ts GENREADY_E2E_SEO=yoast GENREADY_E2E_PHP=7.4
run "seo-plugins (Rank Math)" rankmath.json seo-plugins.spec.ts GENREADY_E2E_SEO=rankmath
run "seo-plugins (All in One SEO)" aioseo.json seo-plugins.spec.ts GENREADY_E2E_SEO=aioseo
run "no-frontend-tracking" base.json no-frontend-tracking.spec.ts
run "multisite" multisite.json multisite.spec.ts GENREADY_E2E_MULTISITE=1 GENREADY_E2E_SITE_URL=http://127.0.0.1
run "uninstall (release copy)" uninstall.json uninstall.spec.ts "${COPY[@]}" GENREADY_E2E_MOUNT_AT=/e2e-src/genready
run "plugin-check (release copy)" plugin-check.json plugin-check.spec.ts "${COPY[@]}" GENREADY_E2E_PLUGIN_CHECK=1
run "connect-key (release zip)" release.json connect-key.spec.ts "${ZIP[@]}"
run "plugin-check (release zip)" release-plugin-check.json plugin-check.spec.ts "${ZIP[@]}" GENREADY_E2E_PLUGIN_CHECK=1
# Onboarding v2 (plan C2). The account spec needs 2 Playground workers (spec 7.5) and more free memory.
anon_reset
run "first-screen" base.json first-screen.spec.ts
run "scan-error" base.json scan-error.spec.ts
run "recent-scans" base.json recent-scans.spec.ts
seed pro
run "switch-off" base.json switch-off.spec.ts
anon_reset
run "account (2 workers)" base.json account.spec.ts GENREADY_E2E_WORKERS=2 GENREADY_E2E_MIN_MB=1900
anon_reset
seed pro
seed free
run "free-plan" free.json free-plan.spec.ts
seed pro

# admin.js busy states: a page without WordPress, no Playground needed.
start=$(date +%s)
if (cd "$E2E_DIR" && npx playwright test specs/admin-busy.spec.ts specs/onboarding-ui.spec.ts); then
	printf '| %-30s | %-26s | %-3s | PASS | %4ss |\n' "admin-busy, onboarding-ui (no WordPress)" "-" "-" "$(($(date +%s) - start))" | tee -a "$SUMMARY"
else
	printf '| %-30s | %-26s | %-3s | FAIL | %4ss |\n' "admin-busy, onboarding-ui (no WordPress)" "-" "-" "$(($(date +%s) - start))" | tee -a "$SUMMARY"
	failed=1
fi

echo
cat "$SUMMARY"
exit "$failed"
