#!/bin/sh

# The initial scaffold has no implementation or tests. Once either exists,
# run the normal checks, including failures for missing tests or coverage.
set -eu

if [ -z "$(find src tests -type f -name '*.php' -print)" ]; then
    echo "Empty package scaffold: tests and coverage are not applicable yet."
    exit 0
fi

exec "$@"
