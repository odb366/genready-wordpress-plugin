# Host safety for Playground runs. Source it, then call wait_for_host.
# Refuses while a GenReady deploy runs or when 1200 MB or less memory is available; waits up to 10 minutes.

# PIDs of running Playground processes (npx, npm exec, node). Shells whose command line merely
# mentions the name are skipped.
playground_pids() {
	local pid
	for pid in $(pgrep -f 'wp-playground' || true); do
		case "$(ps -o comm= -p "$pid" 2>/dev/null)" in
			bash | sh | zsh | '') ;;
			*) echo "$pid" ;;
		esac
	done
}

host_ok() {
	local status avail
	status=$(gh run list --repo odb366/genready-ai --workflow "Deploy GenReady" --limit 1 --json status -q '.[0].status' 2>/dev/null || echo unknown)
	avail=$(free -m | awk 'NR==2 {print $7}')
	if [ "$status" = "in_progress" ] || [ "$status" = "queued" ] || [ "$status" = "unknown" ] || [ -z "$status" ]; then
		echo "host check: deploy status is '$status'" >&2
		return 1
	fi
	if [ "$avail" -le 1200 ]; then
		echo "host check: only ${avail} MB available" >&2
		return 1
	fi
	echo "host check: deploy '$status', ${avail} MB available"
}

wait_for_host() {
	local waited=0
	until host_ok; do
		if [ "$waited" -ge 600 ]; then
			echo "BLOCKED: host check failed for 10 minutes" >&2
			exit 3
		fi
		sleep 30
		waited=$((waited + 30))
	done
	if [ -n "$(playground_pids)" ]; then
		echo "BLOCKED: another Playground is running" >&2
		exit 3
	fi
}
