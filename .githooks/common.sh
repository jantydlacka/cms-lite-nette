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
