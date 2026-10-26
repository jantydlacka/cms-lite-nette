# Shared by the git hooks. Hooks run on the host and execute tools in the app container.

dc() {
	docker compose exec -T app "$@"
}

require_stack() {
	if ! docker compose exec -T app true > /dev/null 2>&1; then
		echo "ERROR: the app container is not running – start it with: docker compose up -d" >&2
		exit 1
	fi
}

# Runs bin/check-forbidden-words in the container; warns loudly when it had no list.
check_forbidden_words() {
	local output status=0
	output="$(dc bin/check-forbidden-words "$@")" || status=$?
	if [ "$status" -ne 0 ]; then
		[ -n "$output" ] && echo "$output" >&2
		echo "ERROR: forbidden word check failed" >&2
		exit 1
	fi
	case "$output" in
		*"no word list, skipped"*)
			echo "WARNING: forbidden word list missing – check NOT run" >&2
			;;
	esac
}

# Released migrations must stay unchanged: compares against origin/main, or against local main
# (from the merge base) without a remote; skipped loudly when neither exists or HEAD is main itself.
check_migrations_immutable() {
	local guard
	guard="$(dirname "${BASH_SOURCE[0]}")/../bin/check-migrations-immutable"
	if git rev-parse --verify --quiet origin/main > /dev/null; then
		"$guard" origin/main
		return
	fi
	if ! git rev-parse --verify --quiet refs/heads/main > /dev/null; then
		echo "WARNING: neither origin/main nor main exists – migration guard skipped" >&2
		return 0
	fi
	if [ "$(git symbolic-ref --quiet --short HEAD || true)" = main ]; then
		echo "WARNING: no origin/main and HEAD is main – migration guard skipped" >&2
		return 0
	fi
	echo "NOTE: no origin/main – migration guard compares against local main" >&2
	"$guard" "$(git merge-base HEAD refs/heads/main)"
}
