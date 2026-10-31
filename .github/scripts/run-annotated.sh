#!/usr/bin/env bash
# Runs a command; if it fails, publishes the last lines of its output as a
# GitHub error annotation so failures can be read from the PR checks page
# (and the checks API) without downloading the full job log.
#
#   .github/scripts/run-annotated.sh "<title>" <command...>
set -uo pipefail

title="$1"; shift
log="$(mktemp)"

"$@" 2>&1 | tee "$log"
status="${PIPESTATUS[0]}"

if [ "$status" -ne 0 ]; then
    tail -n 60 "$log" \
        | sed -e 's/\x1b\[[0-9;]*m//g' -e 's/%/%25/g' -e 's/\r/%0D/g' \
        | awk 'BEGIN{ORS="%0A"} {print}' \
        | { read -r body || true; echo "::error title=${title}::${body}"; }
fi

exit "$status"
