#!/bin/sh

# Run every check, even when an earlier check fails.
status=0

mago fmt --check || status=1
php scripts/sort-imports.php --check || status=1
mago lint || status=1
mago analyze || status=1
mago guard || status=1

exit "$status"
